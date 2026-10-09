<?php

namespace App\Domain\Billing\Issuing;

use App\Enums\ProjectStatus;
use App\Enums\SalesDocumentType;
use App\Enums\TaxRateKind;
use App\Models\CatalogService;
use App\Models\Client;
use App\Models\HourBank;
use App\Models\NumberingCounter;
use App\Models\NumberingSeries;
use App\Models\PaymentMethod;
use App\Models\Project;
use App\Models\TaxRate;
use App\Support\LocalTime;

/**
 * Lo que ofrece el editor de factura (PLAN-EMISION §6.2; D-428): clientes con su ficha fiscal (y lo
 * que les falta para emitir), proyectos con sus bolsas, el catálogo de servicios, los impuestos, las
 * retenciones, las series de facturas y las formas de pago. Un número fijo de consultas, sin N+1.
 */
final class EditorOptions
{
    /**
     * @return array{clients: list<array<string, mixed>>, projects: list<array{id: int, code: string, name: string, client_id: int|null, banks: list<array{id: int, name: string}>}>, services: list<array<string, mixed>>, taxes: list<array<string, mixed>>, withholdings: list<array<string, mixed>>, series: list<array<string, mixed>>, payment_methods: list<array{id: int, name: string, due_days: int|null, is_default: bool}>, issuer_missing: list<string>, today: string}
     */
    public static function all(): array
    {
        $clients = Client::query()->with('billingProfile')->orderBy('name')->orderBy('id')->get();

        return [
            'clients' => array_values($clients->map(fn (Client $client): array => [
                'id' => $client->id,
                'name' => $client->name,
                'missing' => FiscalParties::clientMissing($client),
                'profile' => [
                    'tax_id' => $client->tax_id,
                    'legal_name' => $client->billingProfile?->legal_name,
                    'eu_vat_number' => $client->billingProfile?->eu_vat_number,
                    'address' => $client->billingProfile?->address,
                    'postal_code' => $client->billingProfile?->postal_code,
                    'city' => $client->billingProfile?->city,
                    'province' => $client->billingProfile?->province,
                    'country_code' => $client->billingProfile->country_code ?? 'ES',
                    'tax_regime' => $client->billingProfile->tax_regime->value ?? 'general',
                    'language' => $client->billingProfile->language ?? 'es',
                    'payment_days' => $client->billingProfile?->payment_days,
                    // Lo demás de la ficha, para no perderlo al completarla desde el editor.
                    'payment_method' => $client->billingProfile?->payment_method?->value,
                    'payment_day' => $client->billingProfile?->payment_day,
                    'billing_emails' => $client->billingProfile->billing_emails ?? [],
                ],
            ])->all()),
            'projects' => self::projects(),
            'services' => array_values(CatalogService::query()->active()->orderBy('name')->orderBy('id')->get()->map(fn (CatalogService $service): array => [
                'id' => $service->id,
                'code' => $service->code,
                'name' => $service->name,
                'description' => $service->description,
                'unit' => $service->unit->value,
                'unit_price' => SalesDocumentPresenter::plain((string) $service->unit_price),
                'tax_rate_id' => $service->tax_rate_id,
            ])->all()),
            'taxes' => self::taxes(TaxRateKind::Vat),
            'withholdings' => self::taxes(TaxRateKind::Withholding),
            'series' => self::series(SalesDocumentType::Invoice),
            'payment_methods' => array_values(PaymentMethod::query()->active()->orderBy('position')->orderBy('id')->get()->map(fn (PaymentMethod $method): array => [
                'id' => $method->id,
                'name' => $method->name,
                'due_days' => $method->due_days,
                'is_default' => $method->is_default,
            ])->all()),
            'issuer_missing' => FiscalParties::issuerMissing(),
            'today' => LocalTime::today()->toDateString(),
        ];
    }

    /**
     * Proyectos con cliente (no internos ni archivados) y sus bolsas, para enlazar una factura (dos
     * consultas).
     *
     * @return list<array{id: int, code: string, name: string, client_id: int|null, banks: list<array{id: int, name: string}>}>
     */
    public static function projects(): array
    {
        $projects = Project::query()
            ->whereNotNull('client_id')
            ->where('billing_type', '!=', 'internal')
            ->where('status', '!=', ProjectStatus::Archived->value)
            ->orderBy('code')->orderBy('id')
            ->get(['id', 'code', 'name', 'client_id', 'billing_type']);
        $banks = HourBank::query()
            ->whereIn('project_id', $projects->modelKeys())
            ->orderByDesc('start_date')->orderBy('id')
            ->get(['id', 'name', 'project_id', 'start_date'])
            ->groupBy('project_id');

        return array_values($projects->map(fn (Project $project): array => [
            'id' => $project->id,
            'code' => $project->code,
            'name' => $project->name,
            'client_id' => $project->client_id,
            'banks' => array_values(($banks->get($project->id) ?? collect())->map(fn (HourBank $bank): array => ['id' => $bank->id, 'name' => $bank->name])->all()),
        ])->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function taxes(TaxRateKind $kind): array
    {
        return array_values(TaxRate::query()->active()->where('kind', $kind->value)->orderBy('position')->orderBy('id')->get()->map(fn (TaxRate $rate): array => [
            'id' => $rate->id,
            'key' => $rate->key,
            'name' => $rate->name,
            'rate' => (string) $rate->rate,
            'operation_type' => $rate->operation_type?->value,
            'is_default' => $rate->is_default,
        ])->all());
    }

    /**
     * Series de un tipo con el número que toca este año (para el diálogo de emitir).
     *
     * @return list<array<string, mixed>>
     */
    public static function series(SalesDocumentType $type): array
    {
        $year = LocalTime::today()->year;
        $series = NumberingSeries::query()->active()->where('document_type', $type->value)->orderByDesc('is_default')->orderBy('code')->get();
        $counters = NumberingCounter::query()->whereIn('series_id', $series->modelKeys())->where('year', $year)->get()->keyBy('series_id');

        return array_values($series->map(fn (NumberingSeries $one): array => [
            'id' => $one->id,
            'code' => $one->code,
            'name' => $one->name,
            'format' => $one->format,
            'is_test' => $one->isTest(),
            'is_default' => $one->is_default,
            'starts_on' => $one->starts_on?->toDateString(),
            'next' => $one->formatNumber($year, ($counters->get($one->id)->last_number ?? 0) + 1),
        ])->all());
    }
}
