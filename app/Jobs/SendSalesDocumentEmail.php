<?php

namespace App\Jobs;

use App\Domain\Billing\Issuing\InvoiceEmailSettings;
use App\Domain\Billing\Issuing\InvoiceLinks;
use App\Domain\Billing\Issuing\InvoicePdf;
use App\Mail\SalesDocumentMail;
use App\Models\Attachment;
use App\Models\SalesDocumentEmail;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Envía una factura propia por email (PLAN-EMISION §3.4; E2, D-438) en la cola `mail`: el PDF
 * archivado (generándolo si aún no está; su SHA-256 queda en el envío) y los adjuntos visibles para el
 * cliente, con el remitente de Ajustes. Un solo intento: repetirlo podría mandar el correo dos veces;
 * si falla, el envío queda «No enviado» con el error en la historia de la factura (y, si era un
 * recordatorio, en «Requiere atención»), y se puede volver a enviar.
 */
class SendSalesDocumentEmail implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public readonly int $emailId)
    {
        $this->onQueue('mail');
    }

    public function handle(InvoicePdf $pdf): void
    {
        SendReportDelivery::ensureMemory();

        $email = SalesDocumentEmail::query()->with('document.attachments')->find($this->emailId);
        if ($email === null || $email->status !== SalesDocumentEmail::QUEUED) {
            return;
        }

        try {
            $document = $email->document;
            $content = $pdf->archive($document);
            $document->refresh();
            $extension = $document->pdf_path !== null ? pathinfo($document->pdf_path, PATHINFO_EXTENSION) : $pdf->extension();
            $expires = CarbonImmutable::now()->addDays(InvoiceEmailSettings::LINK_DAYS);

            $files = [[
                'name' => $document->pdfFilename($extension),
                'content' => $content,
                'mime' => $extension === 'pdf' ? 'application/pdf' : 'text/html',
            ]];
            foreach ($document->attachments->filter(fn (Attachment $one): bool => $one->client_visible) as $attachment) {
                $disk = Storage::disk($attachment->disk);
                if ($disk->exists($attachment->path)) {
                    $files[] = ['name' => $attachment->original_name, 'content' => (string) $disk->get($attachment->path), 'mime' => $attachment->mime];
                }
            }

            $mail = new SalesDocumentMail(
                subjectLine: $email->subject,
                body: $email->body,
                link: InvoiceLinks::show($document, $email, $expires),
                language: $email->language,
                credit: $document->isCreditNote(),
                testMode: $email->test_mode,
                files: $files,
            );

            $pending = Mail::to($email->to);
            if ($email->cc !== null && $email->cc !== []) {
                $pending->cc($email->cc);
            }
            if ($email->bcc !== null && $email->bcc !== []) {
                $pending->bcc($email->bcc);
            }
            $sent = $pending->send($mail);

            $now = CarbonImmutable::now();
            $email->forceFill([
                'status' => SalesDocumentEmail::SENT,
                'sent_at' => $now,
                'pdf_sha256' => $document->pdf_sha256 ?? hash('sha256', $content),
                'message_id' => $sent?->getMessageId(),
                'error' => null,
            ])->save();

            // El primer envío de verdad (no el de prueba) marca la factura como enviada.
            if (! $email->test_mode && $document->sent_at === null) {
                $document->newQuery()->whereKey($document->id)->whereNull('sent_at')->update(['sent_at' => $now]);
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->markFailed($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->markFailed($exception);
    }

    private function markFailed(?Throwable $exception): void
    {
        SalesDocumentEmail::query()->whereKey($this->emailId)->where('status', SalesDocumentEmail::QUEUED)->update([
            'status' => SalesDocumentEmail::FAILED,
            'error' => mb_substr($exception?->getMessage() ?? __('invoicing.emails.errors.failed'), 0, 500),
        ]);
    }
}
