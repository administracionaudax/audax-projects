<?php

namespace App\Domain\Billing\Issuing;

use App\Domain\Weeklies\AppModules;
use App\Enums\AppModule;
use App\Enums\SalesDocumentStatus;
use App\Jobs\SendSalesDocumentEmail;
use App\Models\Attachment;
use App\Models\HoldedContact;
use App\Models\PaymentReminderRule;
use App\Models\SalesDocument;
use App\Models\SalesDocumentEmail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El envío de una factura propia por email (PLAN-EMISION §3.4; E2, D-438 a D-441):
 * - **Componer** (el diálogo): destinatarios propuestos (los emails de facturación del cliente, su
 *   contacto, sus contactos de Holded, sus usuarios del portal y los del último envío), la plantilla
 *   del tipo en el idioma del cliente con las variables ya puestas (salvo [enlace]) y lo que se adjunta.
 * - **Enviar**: guarda el envío (`sales_document_emails`) con el texto final y el enlace firmado de
 *   ese envío, y lo encola en `mail` (SendSalesDocumentEmail), que adjunta el PDF archivado (con su
 *   SHA-256) y los adjuntos visibles para el cliente. Copia oculta a Audax si está en Ajustes.
 * - **Modo de prueba** (D-239: el módulo apagado y visto solo por los admins): nunca sale nada hacia
 *   el cliente. El correo le llega solo a quien lo envía y queda marcado como prueba. Los
 *   recordatorios automáticos ni siquiera corren (PaymentReminders).
 */
final class InvoiceMailer
{
    public const int MAX_RECIPIENTS = 10;

    /** ¿Se puede enviar? Una factura o rectificativa emitida (no anulada ni borrador). */
    public static function sendable(SalesDocument $document): bool
    {
        return $document->status === SalesDocumentStatus::Issued;
    }

    /** ¿En modo de prueba? (el módulo no está encendido de verdad: solo lo ven los admins). */
    public static function testMode(): bool
    {
        return ! (AppModules::enabled(AppModule::Billing) && AppModules::enabled(AppModule::Invoicing));
    }

    /**
     * Lo que pinta el diálogo de envío.
     *
     * @return array<string, mixed>
     */
    public function compose(SalesDocument $document, User $viewer, bool $reminder = false): array
    {
        $kind = EmailTemplates::kindFor($document, $reminder);
        $language = EmailTemplates::language($document->language);
        $template = EmailTemplates::get($kind, $language);
        $values = EmailTemplates::variables($document);
        $suggestions = self::suggestions($document);
        $settings = InvoiceEmailSettings::get();

        return [
            'kind' => $kind,
            'language' => $language,
            'subject' => EmailTemplates::fill($template['subject'], $values),
            'body' => EmailTemplates::fill($template['body'], $values),
            'suggestions' => $suggestions,
            'to' => array_values(array_map(fn (array $one): string => $one['email'], array_filter($suggestions, fn (array $one): bool => $one['default']))),
            'attachments' => [
                $document->pdfFilename(),
                ...$document->attachments()->where('client_visible', true)->pluck('original_name')->all(),
            ],
            'from' => ['name' => $settings['sender_name'], 'email' => InvoiceEmailSettings::fromAddress()],
            'reply_to' => $settings['reply_to'],
            'bcc' => $settings['bcc'],
            'link_days' => InvoiceEmailSettings::LINK_DAYS,
            'test_mode' => self::testMode(),
            'self_email' => $viewer->email,
        ];
    }

    /**
     * Guarda el envío y lo encola. Sin destinatarios válidos, o si la factura ya no se puede enviar,
     * error de validación.
     *
     * @param  array{to: list<string>, cc?: list<string>, subject: string, body: string}  $data
     */
    public function send(SalesDocument $document, array $data, ?User $by, string $kind = 'send', ?PaymentReminderRule $rule = null): SalesDocumentEmail
    {
        if (! self::sendable($document)) {
            throw ValidationException::withMessages(['to' => __('invoicing.emails.errors.not_sendable')]);
        }

        $to = self::clean($data['to']);
        $cc = array_values(array_diff(self::clean($data['cc'] ?? []), $to));
        if ($to === []) {
            throw ValidationException::withMessages(['to' => __('invoicing.emails.errors.no_recipients')]);
        }

        $settings = InvoiceEmailSettings::get();
        $bcc = $settings['bcc'] !== null && ! in_array($settings['bcc'], [...$to, ...$cc], true) ? [$settings['bcc']] : [];
        $test = self::testMode();
        if ($test) {
            // Modo de prueba (D-239): solo a quien lo envía; nunca al cliente.
            if ($by === null) {
                throw ValidationException::withMessages(['to' => __('invoicing.emails.errors.test_mode')]);
            }
            [$to, $cc, $bcc] = [[mb_strtolower($by->email)], [], []];
        }

        $template = $kind === 'reminder' ? 'reminder' : EmailTemplates::kindFor($document);
        $document->loadMissing('attachments');

        $email = DB::transaction(function () use ($document, $data, $by, $kind, $rule, $to, $cc, $bcc, $test, $template): SalesDocumentEmail {
            $email = SalesDocumentEmail::query()->create([
                'sales_document_id' => $document->id,
                'kind' => $kind,
                'template' => $template,
                'language' => EmailTemplates::language($document->language),
                'to' => $to,
                'cc' => $cc === [] ? null : $cc,
                'bcc' => $bcc === [] ? null : $bcc,
                'subject' => mb_substr(trim($data['subject']), 0, 255),
                'body' => '',
                'attachments' => [
                    $document->pdfFilename(),
                    ...$document->attachments->filter(fn (Attachment $one): bool => $one->client_visible)->map(fn (Attachment $one): string => $one->original_name)->values()->all(),
                ],
                'test_mode' => $test,
                'reminder_rule_id' => $rule?->id,
                'sent_by' => $by?->id,
            ]);

            // El texto final: las variables que queden (también [enlace], con el enlace de este envío).
            $values = EmailTemplates::variables($document, InvoiceLinks::show($document, $email));
            $email->forceFill([
                'subject' => mb_substr(EmailTemplates::fill($email->subject, $values), 0, 255),
                'body' => EmailTemplates::fill(trim($data['body']), $values),
            ])->save();

            return $email;
        });

        activity('invoicing')
            ->causedBy($by)
            ->performedOn($document)
            ->event($kind === 'reminder' ? 'reminder_queued' : 'email_queued')
            ->withProperties(['email' => $email->id, 'to' => $to, 'cc' => $cc, 'test' => $test, 'rule' => $rule?->id])
            ->log('invoicing.email_queued');

        SendSalesDocumentEmail::dispatch($email->id)->afterCommit();

        return $email;
    }

    /**
     * A quién se le puede mandar: los emails de facturación del cliente (propuestos), su contacto,
     * sus contactos de Holded, sus usuarios del portal y los del último envío.
     *
     * @return list<array{email: string, label: string, source: string, default: bool}>
     */
    public static function suggestions(SalesDocument $document): array
    {
        $client = $document->client()->with(['billingProfile', 'portalUsers' => fn ($q) => $q->where('is_active', true)->select(['id', 'name', 'email', 'client_id'])])->first();
        $found = [];
        $add = function (?string $email, string $label, string $source, bool $default) use (&$found): void {
            $email = $email === null ? '' : mb_strtolower(trim($email));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || isset($found[$email])) {
                return;
            }
            $found[$email] = ['email' => $email, 'label' => $label, 'source' => $source, 'default' => $default];
        };

        $billing = $client?->billingProfile?->billing_emails ?? [];
        foreach ($billing as $email) {
            $add($email, __('invoicing.emails.sources.billing'), 'billing', true);
        }

        $last = SalesDocumentEmail::query()->where('sales_document_id', $document->id)->where('status', '!=', SalesDocumentEmail::FAILED)
            ->where('test_mode', false)->orderByDesc('id')->first();
        foreach ($last->to ?? [] as $email) {
            $add($email, __('invoicing.emails.sources.last'), 'last', $billing === []);
        }

        if ($client !== null) {
            $add($client->contact_email, $client->contact_name ?? __('invoicing.emails.sources.contact'), 'contact', $billing === [] && $last === null);
            foreach (HoldedContact::query()->where('client_id', $client->id)->whereNotNull('email')->orderBy('name')->get(['name', 'email']) as $contact) {
                $add($contact->email, $contact->name, 'holded', false);
            }
            foreach ($client->portalUsers as $user) {
                $add($user->email, __('invoicing.emails.sources.portal', ['name' => $user->name]), 'portal', false);
            }
        }

        return array_values($found);
    }

    /**
     * Emails válidos, en minúsculas, sin repetir y como mucho MAX_RECIPIENTS.
     *
     * @param  list<string>|mixed  $emails
     * @return list<string>
     */
    private static function clean(mixed $emails): array
    {
        $clean = [];
        foreach (is_array($emails) ? $emails : [] as $email) {
            $email = is_string($email) ? mb_strtolower(trim($email)) : '';
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false && ! in_array($email, $clean, true)) {
                $clean[] = $email;
            }
        }

        return array_slice($clean, 0, self::MAX_RECIPIENTS);
    }
}
