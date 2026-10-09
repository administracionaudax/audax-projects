<?php

use App\Domain\Billing\Issuing\DraftWriter;
use App\Domain\Billing\Issuing\InvoiceIssuer;
use App\Models\Client;
use App\Models\ClientBillingProfile;
use App\Models\NumberingSeries;
use App\Models\PaymentMethod;
use App\Models\SalesDocument;
use App\Models\Setting;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
| Utilidades de los tests de la emisión propia (PLAN-EMISION E1). Los impuestos, las formas de pago,
| las instalaciones y las series (F, CN, PRU y PRUCN) los crea la migración (InvoicingDefaults).
*/

/** Enciende Facturación y la emisión propia (las dos vienen apagadas). */
function enableInvoicing(bool $enabled = true): void
{
    Setting::set('modules', [...(array) Setting::get('modules', []), 'billing' => true, 'invoicing' => $enabled]);
}

/** Los datos del emisor completos (Ajustes de Facturación). */
function invoicingIssuer(array $overrides = []): void
{
    Setting::set('billing_issuer', [
        'legal_name' => 'Audax Studio, S.L.',
        'tax_id' => 'B12345678',
        'address' => 'Calle de Colón, 1',
        'postal_code' => '46004',
        'city' => 'València',
        'province' => 'Valencia',
        'country_code' => 'ES',
        'registry' => 'Inscrita en el Registro Mercantil de Valencia, tomo 1, folio 1, hoja V-1',
        'iban' => 'ES9121000418450200051332',
        'email' => 'administracion@example.com',
        'phone' => null,
        'legal_form' => 'S.L.',
        'trade_name' => 'Audax Studio',
        'website' => null,
        ...$overrides,
    ]);
}

/** Un cliente con su ficha fiscal completa. */
function invoicingClient(array $profile = [], array $client = []): Client
{
    $model = Client::factory()->create(['tax_id' => 'B87654321', ...$client]);
    ClientBillingProfile::query()->create([
        'client_id' => $model->id,
        'legal_name' => $model->name.', S.L.',
        'address' => 'Avenida del Puerto, 10',
        'postal_code' => '46021',
        'city' => 'València',
        'province' => 'Valencia',
        'country_code' => 'ES',
        'tax_regime' => 'general',
        'language' => 'es',
        ...$profile,
    ]);

    return $model->refresh();
}

function invoicingSeries(string $code): NumberingSeries
{
    return NumberingSeries::query()->where('code', $code)->firstOrFail();
}

function invoicingTax(string $key): TaxRate
{
    return TaxRate::query()->where('key', $key)->firstOrFail();
}

/**
 * Una línea de concepto para el editor.
 *
 * @return array<string, mixed>
 */
function invoicingLine(string $quantity, string $price, string $tax = 'iva_21', string $discount = '0', string $description = 'Desarrollo web'): array
{
    return ['kind' => 'item', 'description' => $description, 'quantity' => $quantity, 'unit' => 'hour', 'unit_price' => $price, 'discount_pct' => $discount, 'tax_rate_id' => invoicingTax($tax)->id];
}

/**
 * Un borrador de factura (por defecto, en la serie F con la fecha de hoy).
 *
 * @param  list<array<string, mixed>>|null  $lines
 * @param  array<string, mixed>  $overrides
 */
function invoicingDraft(User $by, Client $client, ?array $lines = null, array $overrides = []): SalesDocument
{
    return app(DraftWriter::class)->save(null, [
        'client_id' => $client->id,
        'series_id' => invoicingSeries('F')->id,
        'issue_date' => now('Europe/Madrid')->toDateString(),
        'payment_method_id' => PaymentMethod::query()->where('is_default', true)->value('id'),
        'lines' => $lines ?? [invoicingLine('10', '60')],
        ...$overrides,
    ], $by);
}

/**
 * @param  list<array<string, mixed>>|null  $lines
 * @param  array<string, mixed>  $overrides
 */
function invoicingIssue(User $by, Client $client, ?array $lines = null, array $overrides = []): SalesDocument
{
    return app(InvoiceIssuer::class)->issue(invoicingDraft($by, $client, $lines, $overrides), $by);
}

/**
 * Quita las protecciones de las tablas de la emisión (solo dentro de una transacción que se deshace):
 * los tests de la verificación comprueban que la comprobación delata lo manipulado.
 */
function tamperInvoicing(): void
{
    if (DB::connection()->getDriverName() === 'pgsql') {
        DB::statement('ALTER TABLE invoice_records DISABLE TRIGGER USER');
        DB::statement('ALTER TABLE sales_documents DISABLE TRIGGER USER');
        DB::statement('ALTER TABLE numbering_counters DISABLE TRIGGER USER');

        return;
    }

    foreach (['invoice_records_no_update', 'invoice_records_no_delete', 'invoice_records_chain', 'sales_documents_frozen', 'sales_documents_coherence', 'numbering_counters_update', 'numbering_counters_delete'] as $trigger) {
        DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
    }
}
