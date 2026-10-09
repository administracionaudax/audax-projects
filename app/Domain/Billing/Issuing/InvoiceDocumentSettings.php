<?php

namespace App\Domain\Billing\Issuing;

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

/**
 * Los textos y el logo de la plantilla de las facturas (PLAN-EMISION §4.1, ajuste
 * `invoice_document`; G-4, D-426): el pie (datos de inscripción en el Registro Mercantil u otros), un
 * texto legal (protección de datos, condiciones) y un logo propio de las facturas (si no hay, el de la
 * empresa de /admin/identidad o el de Audax). `validated`: si la gestoría ha validado la plantilla y
 * sus menciones; mientras no, Ajustes lo marca como «pendiente de validar con la gestoría».
 *
 * @phpstan-type InvoiceDocument array{footer: string|null, legal_text: string|null, logo: array{path: string, width: int, height: int, mime: string}|null, validated: bool}
 */
final class InvoiceDocumentSettings
{
    public const string KEY = 'invoice_document';

    public const string LOGO_PATH = 'invoicing/logo';

    /**
     * @return InvoiceDocument
     */
    public static function get(): array
    {
        $stored = Setting::get(self::KEY);
        $stored = is_array($stored) ? $stored : [];
        $logo = $stored['logo'] ?? null;

        return [
            'footer' => is_string($stored['footer'] ?? null) ? $stored['footer'] : null,
            'legal_text' => is_string($stored['legal_text'] ?? null) ? $stored['legal_text'] : null,
            'logo' => is_array($logo) && is_string($logo['path'] ?? null) ? [
                'path' => $logo['path'],
                'width' => (int) ($logo['width'] ?? 0),
                'height' => (int) ($logo['height'] ?? 0),
                'mime' => is_string($logo['mime'] ?? null) ? $logo['mime'] : 'image/png',
            ] : null,
            'validated' => (bool) ($stored['validated'] ?? false),
        ];
    }

    /**
     * @param  InvoiceDocument  $value
     */
    public static function put(array $value): void
    {
        Setting::set(self::KEY, $value);
    }

    /** El logo propio de las facturas como <img> en línea, o null si no hay. */
    public static function logoHtml(string $alt): ?string
    {
        $logo = self::get()['logo'];
        $disk = Storage::disk((string) config('invoicing.disk'));

        if ($logo === null || ! $disk->exists($logo['path'])) {
            return null;
        }

        return '<img class="logo logo--custom" src="data:'.$logo['mime'].';base64,'.base64_encode((string) $disk->get($logo['path'])).'"'
            .' width="'.$logo['width'].'" height="'.$logo['height'].'" alt="'.e($alt).'">';
    }
}
