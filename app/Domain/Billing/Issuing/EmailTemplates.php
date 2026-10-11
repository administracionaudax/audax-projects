<?php

namespace App\Domain\Billing\Issuing;

use App\Models\EmailTemplate;
use App\Models\SalesDocument;

/**
 * Las plantillas de email de la emisión propia (PLAN-EMISION §3.4, H-090 y H-091; E2, D-439): una
 * por tipo (factura, rectificativa, recordatorio) e idioma (español e inglés, el del cliente), con
 * asunto y texto. Las de serie están en lang/es/invoicing.php (`email_templates`); las cambiadas en
 * Ajustes, en `email_templates`. «Volver a la de serie» borra la fila.
 *
 * Variables entre corchetes, como en el plan: [cliente], [numero], [importe], [pendiente],
 * [vencimiento], [enlace], [empresa] y, en la rectificativa, [rectificada]. El texto es plano: en el
 * email se escapa y [enlace] se convierte en un enlace (InvoiceMailer).
 */
final class EmailTemplates
{
    public const array KINDS = ['invoice', 'credit_note', 'reminder'];

    public const array LANGUAGES = ['es', 'en'];

    public const array VARIABLES = ['cliente', 'numero', 'importe', 'pendiente', 'vencimiento', 'enlace', 'empresa', 'rectificada'];

    public const string LINK = '[enlace]';

    /**
     * @return array{subject: string, body: string}
     */
    public static function defaults(string $kind, string $language): array
    {
        $language = self::language($language);

        return [
            'subject' => (string) __("invoicing.email_templates.{$language}.{$kind}.subject"),
            'body' => (string) __("invoicing.email_templates.{$language}.{$kind}.body"),
        ];
    }

    /**
     * La plantilla en uso: la cambiada en Ajustes o la de serie.
     *
     * @return array{subject: string, body: string, custom: bool}
     */
    public static function get(string $kind, string $language): array
    {
        $language = self::language($language);
        $stored = EmailTemplate::query()->where('kind', $kind)->where('language', $language)->first();

        return $stored === null
            ? [...self::defaults($kind, $language), 'custom' => false]
            : ['subject' => $stored->subject, 'body' => $stored->body, 'custom' => true];
    }

    /**
     * Todas, para Ajustes (una consulta).
     *
     * @return list<array{kind: string, language: string, subject: string, body: string, custom: bool, default_subject: string, default_body: string}>
     */
    public static function all(): array
    {
        $stored = EmailTemplate::query()->get()->keyBy(fn (EmailTemplate $template): string => $template->kind.'.'.$template->language);
        $all = [];

        foreach (self::KINDS as $kind) {
            foreach (self::LANGUAGES as $language) {
                $default = self::defaults($kind, $language);
                $one = $stored->get("{$kind}.{$language}");
                $all[] = [
                    'kind' => $kind,
                    'language' => $language,
                    'subject' => $one->subject ?? $default['subject'],
                    'body' => $one->body ?? $default['body'],
                    'custom' => $one !== null,
                    'default_subject' => $default['subject'],
                    'default_body' => $default['body'],
                ];
            }
        }

        return $all;
    }

    /** El tipo de plantilla de un envío: la factura o la rectificativa (o el recordatorio). */
    public static function kindFor(SalesDocument $document, bool $reminder = false): string
    {
        return $reminder ? 'reminder' : ($document->isCreditNote() ? 'credit_note' : 'invoice');
    }

    public static function language(?string $language): string
    {
        return in_array($language, self::LANGUAGES, true) ? (string) $language : 'es';
    }

    /**
     * Los valores de las variables de una factura (en su idioma). [enlace] solo si se pasa.
     *
     * @return array<string, string>
     */
    public static function variables(SalesDocument $document, ?string $link = null): array
    {
        $language = self::language($document->language);
        $snapshot = $document->client_snapshot ?? [];
        $issuer = $document->issuer_snapshot ?? FiscalParties::issuer();
        $company = null;
        foreach (['trade_name', 'legal_name'] as $field) {
            if ($company === null && is_string($issuer[$field] ?? null) && trim($issuer[$field]) !== '') {
                $company = trim($issuer[$field]);
            }
        }
        $document->loadMissing(['rectified:id,full_number', 'rectifiedHolded:id,number']);

        $values = [
            'cliente' => is_string($snapshot['legal_name'] ?? null) && $snapshot['legal_name'] !== '' ? $snapshot['legal_name'] : $document->client_name,
            'numero' => (string) ($document->full_number ?? ''),
            'importe' => InvoicePdf::money((string) $document->total, $language),
            'pendiente' => InvoicePdf::money($document->pendingAmount(), $language),
            'vencimiento' => $document->due_date === null ? '—' : $document->due_date->format('d/m/Y'),
            'empresa' => $company ?? InvoiceEmailSettings::defaultName(),
            'rectificada' => (string) ($document->rectified->full_number ?? $document->rectifiedHolded->number ?? ''),
        ];

        if ($link !== null) {
            $values['enlace'] = $link;
        }

        return $values;
    }

    /**
     * Cambia cada [variable] conocida por su valor; las que no tienen valor se quedan como están.
     *
     * @param  array<string, string>  $values
     */
    public static function fill(string $text, array $values): string
    {
        return (string) preg_replace_callback('/\[([a-z]+)\]/u', fn (array $match): string => array_key_exists($match[1], $values) ? $values[$match[1]] : $match[0], $text);
    }
}
