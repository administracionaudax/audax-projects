<?php

namespace App\Domain\Billing\Issuing;

use App\Models\Setting;
use Illuminate\Mail\Mailables\Address;

/**
 * El remitente de las facturas por email (PLAN-EMISION §3.4, E2; supuesto de la pregunta P-4,
 * D-438), ajuste `invoice_email`:
 * - Se envían **desde la dirección del relé** (`MAIL_FROM_ADDRESS`, en el servidor
 *   administracion@audaxstudio.com, que el relé SMTP de Google Workspace ya acepta). Nunca se cambia
 *   el .env del servidor desde aquí: la dirección de envío no se elige en Ajustes, porque el relé
 *   solo acepta usuarios o alias del dominio.
 * - En Ajustes se eligen el **nombre** que ve el cliente (por defecto, el nombre comercial del
 *   emisor), la dirección de **respuesta** (por defecto, administracion@audaxstudio.com, la misma del
 *   remitente: el propietario quiere que todo salga de ella y vuelva a ella, D-447; vacía, vuelve a
 *   esa) y una **copia oculta** a Audax (opcional; vacía: ninguna).
 * - El enlace firmado de cada envío caduca a los **60 días** (como el de 48 h de Holded, H-098).
 *
 * @phpstan-type InvoiceEmail array{sender_name: string, reply_to: string|null, bcc: string|null}
 */
final class InvoiceEmailSettings
{
    public const string KEY = 'invoice_email';

    public const int LINK_DAYS = 60;

    /**
     * @return InvoiceEmail
     */
    public static function get(): array
    {
        $stored = Setting::get(self::KEY);
        $stored = is_array($stored) ? $stored : [];
        $name = is_string($stored['sender_name'] ?? null) && trim($stored['sender_name']) !== '' ? trim($stored['sender_name']) : self::defaultName();

        return [
            'sender_name' => $name,
            'reply_to' => self::email($stored['reply_to'] ?? null) ?? self::defaultReplyTo(),
            'bcc' => self::email($stored['bcc'] ?? null),
        ];
    }

    /**
     * @param  array{sender_name: string|null, reply_to: string|null, bcc: string|null}  $value
     */
    public static function put(array $value): void
    {
        Setting::set(self::KEY, [
            'sender_name' => $value['sender_name'] === null || trim($value['sender_name']) === '' ? null : trim($value['sender_name']),
            'reply_to' => self::email($value['reply_to']),
            'bcc' => self::email($value['bcc']),
        ]);
    }

    /** La respuesta por defecto (D-447): config invoicing.reply_to, administracion@audaxstudio.com. */
    public static function defaultReplyTo(): ?string
    {
        return self::email(config('invoicing.reply_to'));
    }

    /** La dirección de envío: la del relé (config mail.from.address). */
    public static function fromAddress(): string
    {
        return (string) config('mail.from.address');
    }

    public static function from(): Address
    {
        return new Address(self::fromAddress(), self::get()['sender_name']);
    }

    /** El nombre comercial del emisor (Ajustes › Emisor) o, si no hay, su razón social o el de la empresa. */
    public static function defaultName(): string
    {
        $issuer = FiscalParties::issuer();

        foreach (['trade_name', 'legal_name'] as $field) {
            if (is_string($issuer[$field] ?? null) && trim($issuer[$field]) !== '') {
                return trim($issuer[$field]);
            }
        }

        return (string) Setting::get('company_name', config('app.name'));
    }

    private static function email(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = mb_strtolower(trim($value));

        return $value === '' || filter_var($value, FILTER_VALIDATE_EMAIL) === false ? null : $value;
    }
}
