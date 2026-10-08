<?php

namespace App\Enums;

use Illuminate\Support\Str;

/**
 * Servicio de una línea de factura de Holded para el informe de facturación (D-400), por el nombre
 * del servicio del catálogo de Audax en Holded (y su código, si lo trae). El valor es el de la URL
 * (?servicio[]=) y el orden de los casos, el de los filtros. Lo que no casa con ninguno va a «Otros».
 *
 * | Servicio | Nombre (sin tildes ni mayúsculas) | Código |
 * |---|---|---|
 * | Bolsas de horas | «bolsadehoras», «bolsa de horas», empieza por «bolsa» | BDH |
 * | Fees | empieza por «fee» (Fee MK y RRSS, Fee Producto digital) | FMKRRSS, F_UX |
 * | Desarrollo | contiene «desarrollo» | DES |
 * | Diseño | contiene «diseno» (Diseño Producto UX/UI, Diseño Gráfico) | D_UX_UI, D_GR |
 * | Mantenimiento | contiene «mantenimiento» | |
 * | Auditorías | contiene «auditoria» | |
 * | Marketing y campañas | contiene «marketing» o «campana» (Gestión Campañas…) | |
 * | SEO | la palabra «seo» | SEO |
 * | Herramientas | empieza por «herramienta», o hosting, licencias y servidores | |
 * | Inversión repercutida | empieza por «inversion» | |
 */
enum BillingService: string
{
    case HourBanks = 'bolsas';
    case Fees = 'fees';
    case Development = 'desarrollo';
    case Design = 'diseno';
    case Maintenance = 'mantenimiento';
    case Audits = 'auditorias';
    case Marketing = 'marketing';
    case Seo = 'seo';
    case Tools = 'herramientas';
    case PassThrough = 'inversion';
    case Other = 'otros';

    public static function classify(?string $name, ?string $code = null): self
    {
        $code = strtoupper(trim((string) $code));
        $text = trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii((string) $name))));
        $compact = str_replace(' ', '', $text);
        $words = $text === '' ? [] : explode(' ', $text);

        return match (true) {
            in_array($code, InvoiceLineKind::BANK_CODES, true) || str_contains($compact, 'bolsadehoras') || str_starts_with($text, 'bolsa') => self::HourBanks,
            in_array($code, InvoiceLineKind::FEE_CODES, true) || str_starts_with($text, 'fee') => self::Fees,
            $code === 'DES' || str_contains($text, 'desarrollo') => self::Development,
            in_array($code, ['D_UX_UI', 'D_GR'], true) || str_contains($text, 'diseno') => self::Design,
            str_contains($text, 'mantenimiento') => self::Maintenance,
            str_contains($text, 'auditoria') => self::Audits,
            $code === 'SEO' || in_array('seo', $words, true) => self::Seo,
            // «Fee MK y RRSS» ya es fee; aquí, el resto de marketing y la gestión de campañas.
            str_contains($text, 'marketing') || str_contains($text, 'campana') => self::Marketing,
            str_starts_with($text, 'herramienta') || in_array('hosting', $words, true)
                || in_array('licencia', $words, true) || in_array('servidor', $words, true) => self::Tools,
            str_starts_with($text, 'inversion') => self::PassThrough,
            default => self::Other,
        };
    }

    public function label(): string
    {
        return __("billing.invoicing.services.{$this->value}");
    }

    /**
     * Los servicios de la URL (?servicio[]=), sin repetir y en el orden de los casos.
     *
     * @return list<self>
     */
    public static function fromQuery(mixed $values): array
    {
        $wanted = array_filter((array) $values, 'is_string');

        return array_values(array_filter(self::cases(), fn (self $service): bool => in_array($service->value, $wanted, true)));
    }
}
