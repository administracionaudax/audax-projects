<?php

namespace App\Mail;

use App\Domain\Reports\Delivery\GeneratedReportFile;
use App\Models\Setting;
use App\Notifications\Reports\WeeklyDigestNotification;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Correo con un informe (D-141), con el tema de Audax (config mail.markdown.theme): saludo, el
 * mensaje de quien lo envía, el título y el periodo del informe y el adjunto. Los ficheros de más
 * de 10 MB van como enlace firmado de descarga ($links), que caduca a los 7 días.
 *
 * No va por cola: lo envía App\Jobs\SendReportDelivery, que ya corre en la cola `mail`, uno por
 * destinatario (nadie ve las direcciones de los demás). Responder llega a quien lo envió.
 * Todo texto variable se escapa (HTML y Markdown): nombres, títulos y el mensaje nunca se
 * interpretan como marcas ni como enlaces. Las variables de la vista no se llaman como las
 * propiedades públicas (Mailable las pasa también a la vista y las pisaría).
 */
class ReportDeliveryMail extends Mailable
{
    use Queueable;

    /**
     * @param  list<GeneratedReportFile>  $files  adjuntos
     * @param  list<array{filename: string, url: string, expires_at: CarbonImmutable}>  $links
     */
    public function __construct(
        public readonly string $subjectLine,
        public readonly ?string $recipientName,
        public readonly string $senderName,
        public readonly ?string $senderEmail,
        public readonly string $reportTitle,
        public readonly ?string $period,
        public readonly ?string $note,
        public readonly array $files,
        public readonly array $links,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
            replyTo: $this->senderEmail !== null ? [new Address($this->senderEmail, $this->senderName)] : [],
        );
    }

    public function content(): Content
    {
        $escape = fn (string $text): string => WeeklyDigestNotification::escape($text);
        $noteLines = $this->note === null ? [] : array_values(array_filter(
            array_map(fn (string $line): string => $escape($line), preg_split('/\R/u', $this->note) ?: []),
            fn (string $line): bool => $line !== '',
        ));

        return new Content(
            markdown: 'mail.reports.delivery',
            with: [
                'greeting' => $escape($this->recipientName !== null
                    ? __('report_deliveries.mail.greeting', ['name' => $this->recipientName])
                    : __('report_deliveries.mail.greeting_anonymous')),
                'intro' => $escape(__('report_deliveries.mail.intro', ['sender' => $this->senderName])),
                'noteLines' => $noteLines,
                'title' => $escape($this->reportTitle),
                'periodLine' => $this->period !== null ? $escape(__('report_deliveries.mail.period', ['period' => $this->period])) : null,
                'attachedNote' => $this->files === [] ? null : $escape(trans_choice('report_deliveries.mail.attached', count($this->files))),
                'downloadLinks' => array_map(fn (array $link): array => [
                    'url' => $link['url'],
                    'label' => __('report_deliveries.mail.download', ['file' => $link['filename']]),
                    'expires' => $escape(__('report_deliveries.mail.link_expires', ['date' => $link['expires_at']->setTimezone((string) config('app.display_timezone', 'Europe/Madrid'))->format('d/m/Y')])),
                ], $this->links),
                'linksNote' => $this->links === [] ? null : $escape(__('report_deliveries.mail.too_large')),
                'salutation' => __('notifications.mail.salutation', [
                    'company' => (string) Setting::get('company_name', config('app.name')),
                ]),
            ],
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            fn (GeneratedReportFile $file): Attachment => Attachment::fromPath($file->path)->as($file->filename)->withMime($file->format->mime()),
            $this->files,
        );
    }
}
