<?php

namespace App\Domain\Reports\Delivery;

use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportPeriod;
use App\Mail\ReportDeliveryMail;
use App\Models\ReportDelivery;
use App\Models\ReportDownload;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * Genera y envía un envío en cola (D-141). Lo llama App\Jobs\SendReportDelivery (cola `mail`):
 * 1. Solo si sigue «en cola» (si la cola lo repite, no se envía dos veces).
 * 2. Con los permisos ACTUALES de quien lo envía: desactivado o sin acceso → omitido; si es de una
 *    programación, se pausa y se avisa (SchedulePauser).
 * 3. Quita a las personas destinatarias que ya no están activas.
 * 4. Genera cada formato con ReportFileGenerator. Los ficheros caben como adjunto hasta
 *    MAX_ATTACHMENT_BYTES en total (de menor a mayor); el resto se guarda en el disco privado y
 *    sale como enlace firmado que caduca a los LINK_DAYS días (ReportDownload).
 * 5. Un correo por destinatario (ReportDeliveryMail) y el resultado en el historial y la auditoría.
 * Los ficheros temporales del generador se borran siempre al terminar.
 */
final class ReportDeliverer
{
    public const int MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024;

    public const int LINK_DAYS = 7;

    public const string DISK = 'local';

    public const string DIRECTORY = 'reports/deliveries';

    public function __construct(
        private readonly ReportFileGenerator $generator,
        private readonly ReportAccess $access,
        private readonly SchedulePauser $pauser,
    ) {}

    public function deliver(int $deliveryId): void
    {
        $delivery = ReportDelivery::query()->with(['sender', 'schedule'])->find($deliveryId);

        if ($delivery === null || $delivery->status !== DeliveryStatus::Queued) {
            return;
        }

        $sender = $delivery->sender;

        if (! $sender->isActive()) {
            $this->skip($delivery, PauseReason::OwnerInactive);

            return;
        }

        $request = $delivery->reportRequest();

        if (! $this->access->allows($request, $sender)) {
            $this->skip($delivery, PauseReason::NoAccess);

            return;
        }

        $users = DeliveryRecipients::users($delivery->recipient_user_ids);

        if ($users->isEmpty() && $delivery->recipient_emails === []) {
            $this->skip($delivery, PauseReason::NoRecipients);

            return;
        }

        $files = [];
        $title = $delivery->title;
        $links = [];

        try {
            foreach ($delivery->exportFormats() as $format) {
                $files[] = $this->generator->generate($request, $format, $sender);
            }

            $title = $this->generator->title($request, $sender);
            [$attachments, $links] = $this->split($delivery, $files);
            $period = $this->periodLabel($request);
            $subject = $delivery->subject ?? (string) __('report_deliveries.mail.subject', ['title' => $title]);

            $send = function (string $address, ?string $name) use ($subject, $sender, $title, $period, $delivery, $attachments, $links): void {
                Mail::to($address, $name)->send(new ReportDeliveryMail(
                    subjectLine: $subject,
                    recipientName: $name,
                    senderName: $sender->name,
                    senderEmail: $sender->email,
                    reportTitle: $title,
                    period: $period,
                    note: $delivery->message,
                    files: $attachments,
                    links: $links,
                ));
            };

            foreach ($users as $user) {
                $send($user->email, $user->name);
            }

            foreach ($delivery->recipient_emails as $email) {
                $send($email, null);
            }
        } catch (AuthorizationException) {
            $this->skip($delivery, PauseReason::NoAccess);

            return;
        } catch (Throwable $exception) {
            $this->fail($delivery, $exception);
            report($exception);

            return;
        } finally {
            foreach ($files as $file) {
                if (is_file($file->path)) {
                    @unlink($file->path);
                }
            }
        }

        $delivery->forceFill([
            'title' => Str::limit($title, 197),
            'recipient_user_ids' => $users->modelKeys(),
            'status' => DeliveryStatus::Sent,
            'error' => null,
            'sent_at' => now(),
        ])->save();

        DeliveryAudit::record('report_sent', $delivery, $sender, $this->auditProperties($delivery) + [
            'links' => count($links),
        ]);
    }

    /**
     * La cola lo da por fallido (p. ej., al superar el tiempo máximo).
     */
    public function failed(int $deliveryId, ?Throwable $exception): void
    {
        $delivery = ReportDelivery::query()->find($deliveryId);

        if ($delivery !== null && $delivery->status === DeliveryStatus::Queued) {
            $this->fail($delivery, $exception);
        }
    }

    /**
     * Adjuntos (hasta el límite, de menor a mayor) y enlaces firmados para el resto.
     *
     * @param  list<GeneratedReportFile>  $files
     * @return array{0: list<GeneratedReportFile>, 1: list<array{filename: string, url: string, expires_at: CarbonImmutable}>}
     */
    private function split(ReportDelivery $delivery, array $files): array
    {
        usort($files, fn (GeneratedReportFile $a, GeneratedReportFile $b): int => (int) filesize($a->path) <=> (int) filesize($b->path));

        $attachments = [];
        $links = [];
        $total = 0;
        $expires = CarbonImmutable::now()->addDays(self::LINK_DAYS);

        foreach ($files as $file) {
            $size = (int) filesize($file->path);

            if ($total + $size <= self::MAX_ATTACHMENT_BYTES) {
                $attachments[] = $file;
                $total += $size;

                continue;
            }

            $id = (string) Str::uuid();
            $path = Storage::disk(self::DISK)->putFileAs(self::DIRECTORY, new File($file->path), $id.'.'.$file->format->extension());

            if ($path === false) {
                throw new \RuntimeException('No se ha podido guardar el informe para su descarga.');
            }

            $download = ReportDownload::query()->create([
                'id' => $id,
                'delivery_id' => $delivery->id,
                'disk' => self::DISK,
                'path' => $path,
                'filename' => $file->filename,
                'mime' => $file->format->mime(),
                'size_bytes' => $size,
                'expires_at' => $expires,
            ]);

            $links[] = [
                'filename' => $file->filename,
                'url' => URL::temporarySignedRoute('reports.downloads.show', $expires, ['download' => $download->id]),
                'expires_at' => $expires,
            ];
        }

        return [$attachments, $links];
    }

    /**
     * Periodo legible del informe ya resuelto: «septiembre de 2026» o «del 01/09/2026 al
     * 07/09/2026». La bolsa de horas no tiene periodo.
     */
    private function periodLabel(ReportRequest $request): ?string
    {
        if ($request->kind === ReportKind::HourBank) {
            return null;
        }

        $filters = ReportFilters::fromQuery($request->query);

        if ($filters->period === ReportPeriod::Month) {
            return $filters->from->settings(['locale' => 'es'])->isoFormat('MMMM [de] YYYY');
        }

        if ($filters->period === ReportPeriod::Year) {
            return (string) $filters->from->year;
        }

        return (string) __('report_deliveries.mail.range', [
            'from' => $filters->from->format('d/m/Y'),
            'to' => $filters->to->format('d/m/Y'),
        ]);
    }

    private function skip(ReportDelivery $delivery, PauseReason $reason): void
    {
        $delivery->forceFill(['status' => DeliveryStatus::Skipped, 'error' => $reason->label()])->save();
        DeliveryAudit::record('report_skipped', $delivery, $delivery->sender, $this->auditProperties($delivery) + ['reason' => $reason->value]);

        $schedule = $delivery->schedule;

        if ($schedule !== null && $schedule->is_active) {
            $this->pauser->pause($schedule, $reason);
        }
    }

    private function fail(ReportDelivery $delivery, ?Throwable $exception): void
    {
        $error = $exception === null ? 'Sin detalle' : Str::limit($exception->getMessage() !== '' ? $exception->getMessage() : $exception::class, 1000);

        $delivery->forceFill(['status' => DeliveryStatus::Failed, 'error' => $error])->save();
        DeliveryAudit::record('report_send_failed', $delivery, $delivery->sender, $this->auditProperties($delivery));
    }

    /**
     * @return array<string, mixed>
     */
    private function auditProperties(ReportDelivery $delivery): array
    {
        return [
            'kind' => $delivery->request['kind'],
            'schedule_id' => $delivery->schedule_id,
            'formats' => $delivery->formats,
            'recipient_user_ids' => $delivery->recipient_user_ids,
            'recipient_emails' => $delivery->recipient_emails,
        ];
    }
}
