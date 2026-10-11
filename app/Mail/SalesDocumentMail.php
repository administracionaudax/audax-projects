<?php

namespace App\Mail;

use App\Domain\Billing\Issuing\InvoiceEmailSettings;
use App\Notifications\Reports\WeeklyDigestNotification;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Email de una factura propia (PLAN-EMISION §3.4; E2, D-438 a D-441) con el tema de Audax: el texto
 * de la plantilla (ya con sus variables), el botón al enlace firmado para verla y descargarla, el
 * PDF y los adjuntos visibles. Lo envía SendSalesDocumentEmail, que ya corre en la cola `mail`.
 *
 * Remitente: la dirección del relé con el nombre de Ajustes; responder llega a la dirección de
 * respuesta de Ajustes (o al remitente). Todo el texto se escapa (HTML y Markdown); una URL del texto
 * (la de [enlace]) se convierte en un enlace con su nombre, nunca en marcas.
 */
class SalesDocumentMail extends Mailable
{
    use Queueable;

    /**
     * @param  list<array{name: string, content: string, mime: string}>  $files
     */
    public function __construct(
        public readonly string $subjectLine,
        public readonly string $body,
        public readonly string $link,
        public readonly string $language,
        public readonly bool $credit,
        public readonly bool $testMode,
        public readonly array $files,
    ) {}

    public function envelope(): Envelope
    {
        $settings = InvoiceEmailSettings::get();

        return new Envelope(
            from: InvoiceEmailSettings::from(),
            replyTo: $settings['reply_to'] !== null ? [new Address($settings['reply_to'], $settings['sender_name'])] : [],
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        $language = $this->language === 'en' ? 'en' : 'es';
        $text = fn (string $key, array $replace = []): string => (string) __("invoicing.emails.mail.{$language}.{$key}", $replace);
        $label = $text($this->credit ? 'open_credit' : 'open');

        return new Content(
            markdown: 'mail.invoicing.document',
            with: [
                'paragraphs' => self::paragraphs($this->body, $label),
                'testNotice' => $this->testMode ? WeeklyDigestNotification::escape((string) __('invoicing.emails.mail.test_notice')) : null,
                'buttonUrl' => $this->link,
                'company' => InvoiceEmailSettings::get()['sender_name'],
                'buttonLabel' => $label,
                'linkNote' => WeeklyDigestNotification::escape($text('link_note', [
                    'date' => CarbonImmutable::now()->addDays(InvoiceEmailSettings::LINK_DAYS)->setTimezone((string) config('app.display_timezone', 'Europe/Madrid'))->format('d/m/Y'),
                ])),
            ],
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            fn (array $file): Attachment => Attachment::fromData(fn (): string => $file['content'], $file['name'])->withMime($file['mime']),
            $this->files,
        );
    }

    /**
     * El texto en párrafos (separados por una línea en blanco), con las líneas de cada uno unidas por
     * un salto; cada trozo escapado y cada URL como enlace con $label.
     *
     * @return list<string>
     */
    public static function paragraphs(string $body, string $label): array
    {
        $paragraphs = [];

        foreach (preg_split('/\R\s*\R/u', trim($body)) ?: [] as $paragraph) {
            $lines = [];
            foreach (preg_split('/\R/u', trim($paragraph)) ?: [] as $line) {
                $parts = preg_split('/(https?:\/\/\S+)/u', $line, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
                $html = '';
                foreach ($parts as $index => $part) {
                    $html .= $index % 2 === 1
                        ? '<a href="'.e($part).'">'.e($label).'</a>'
                        : (trim($part) === '' ? $part : (preg_match('/^\s/u', $part) === 1 ? ' ' : '').WeeklyDigestNotification::escape($part).(preg_match('/\s$/u', $part) === 1 ? ' ' : ''));
                }
                if (trim($html) !== '') {
                    $lines[] = $html;
                }
            }
            if ($lines !== []) {
                $paragraphs[] = implode("<br>\n", $lines);
            }
        }

        return $paragraphs;
    }
}
