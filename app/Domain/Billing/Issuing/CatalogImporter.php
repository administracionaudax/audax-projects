<?php

namespace App\Domain\Billing\Issuing;

use App\Enums\BillingService;
use App\Enums\InvoiceLineKind;
use App\Enums\ServiceUnit;
use App\Enums\TaxOperationType;
use App\Enums\TaxRateKind;
use App\Models\CatalogService;
use App\Models\HoldedInvoiceLine;
use App\Models\TaxRate;

/**
 * El catálogo de servicios a partir de las líneas de las facturas de Holded ya leídas (PLAN-EMISION
 * §4.1; D-425). La clave de Holded de D-399 es de solo lectura con los ámbitos Contactos, Proyectos y
 * Ventas: el catálogo de productos y servicios de Holded queda fuera de esos ámbitos, así que no se
 * pide a su API (y nunca se escribe en Holded). Cada código distinto de las líneas (BDH, DES, F_UX…)
 * da un servicio con el nombre, el precio y el IVA de su línea más reciente, y su unidad según lo que
 * vende (D-396): horas en bolsas y servicios por horas, meses en los fees. Lo que ya existe (por
 * código) no se toca.
 */
final class CatalogImporter
{
    /**
     * @return array{created: int, skipped: int}
     */
    public function fromHoldedLines(): array
    {
        $existing = CatalogService::query()->whereNotNull('code')->pluck('code')->map(fn (mixed $code): string => strtoupper((string) $code))->all();
        $rates = TaxRate::query()->active()->where('kind', TaxRateKind::Vat->value)->where('operation_type', TaxOperationType::S1->value)->get(['id', 'rate']);
        $default = TaxRate::query()->active()->where('kind', TaxRateKind::Vat->value)->where('is_default', true)->value('id');

        $latest = [];
        foreach (HoldedInvoiceLine::query()->whereNotNull('service_code')->where('service_code', '!=', '')->orderByDesc('id')->get(['id', 'service_code', 'name', 'units', 'unit_price', 'tax_rate']) as $line) {
            $code = strtoupper(trim((string) $line->service_code));
            $latest[$code] ??= $line;
        }

        $created = 0;
        $skipped = 0;
        foreach ($latest as $code => $line) {
            if (in_array($code, $existing, true)) {
                $skipped++;

                continue;
            }

            $kind = InvoiceLineKind::classify($code, $line->name, (string) $line->units);
            $rate = $line->tax_rate === null ? null : $rates->first(fn (TaxRate $one): bool => bccomp(DocumentTotals::num((string) $one->rate), DocumentTotals::num((string) $line->tax_rate), 2) === 0);

            CatalogService::query()->create([
                'code' => $code,
                'name' => trim((string) ($line->name ?? $code)) ?: $code,
                'unit' => match ($kind) {
                    InvoiceLineKind::HourBank, InvoiceLineKind::Hours => ServiceUnit::Hour,
                    InvoiceLineKind::Fee => ServiceUnit::Month,
                    default => ServiceUnit::Unit,
                },
                'unit_price' => (string) $line->unit_price,
                'tax_rate_id' => $rate->id ?? $default,
                'category' => BillingService::classify($line->name, $code),
            ]);
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }
}
