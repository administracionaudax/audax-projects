<?php

use App\Domain\Billing\Holded\HoldedApi;
use App\Enums\BillingType;
use App\Models\CatalogService;
use App\Models\HoldedContact;
use App\Models\Project;
use App\Models\RecurringInvoice;
use App\Models\SalesDocument;
use Carbon\CarbonImmutable;

/*
| Importar las recurrentes de Holded en pausa (PLAN-EMISION §7.2; E3) con un Holded falso: solo se
| lee (nunca se escribe en Holded), se crean pausadas con su cliente casado, líneas, periodicidad y
| próxima fecha, y lo que no casa se dice y no se inventa. --dry-run no crea nada.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00:00', 'Europe/Madrid'));
    enableInvoicing();
    $this->admin = userWithRole('admin');
    $this->client = invoicingClient([], ['name' => 'Bodegas Arrieta']);
    HoldedContact::query()->create(['holded_id' => 'c-arrieta', 'name' => 'Bodegas Arrieta, S.L.', 'client_id' => $this->client->id, 'match_method' => 'tax_id']);
    HoldedContact::query()->create(['holded_id' => 'c-libre', 'name' => 'Estudio Nébula, S.L.']);
    $this->fee = Project::factory()->create(['client_id' => $this->client->id, 'billing_type' => BillingType::MonthlyFee, 'monthly_fee_amount' => '1200.00', 'status' => 'active']);
    CatalogService::query()->create(['code' => 'F_UX', 'name' => 'Fee Producto digital', 'unit' => 'month', 'unit_price' => '1200']);

    $line = fn (string $name, string $price, ?string $code, string $description, int $units = 1): array => [
        'name' => $name, 'code' => $code, 'description' => $description, 'units' => $units, 'price' => $price, 'discount' => '0', 'taxes' => ['s_iva_21'],
    ];
    $this->fake = holdedFake(['recurring' => [
        ['id' => 'r-fee', 'name' => 'Fee Arrieta', 'contact_id' => 'c-arrieta', 'contact_name' => 'Bodegas Arrieta, S.L.', 'interval' => 'month', 'frequency' => 1,
            'start_date' => '2026-01-29', 'next_date' => '2026-10-29', 'status' => 'active', 'items' => [$line('Fee Producto digital', '1200', 'F_UX', 'Sprint de [mes] [año]')]],
        ['id' => 'r-quarter', 'contact_id' => 'c-arrieta', 'contact_name' => 'Bodegas Arrieta, S.L.', 'interval' => 'quarterly',
            'next_date' => '2027-01-15', 'items' => [$line('Mantenimiento', '450', 'MANT', 'Mantenimiento del [trimestre]')]],
        ['id' => 'r-nebula', 'contact_id' => 'c-libre', 'contact_name' => 'Estudio Nébula, S.L.', 'interval' => 'month', 'next_date' => '2026-10-29',
            'items' => [$line('Fee MK y RRSS', '800', 'FMKRRSS', 'Fee de [mes]')]],
        ['id' => 'r-weekly', 'contact_id' => 'c-arrieta', 'contact_name' => 'Bodegas Arrieta, S.L.', 'interval' => 'week', 'next_date' => '2026-10-19',
            'items' => [$line('Algo', '10', null, 'Cada semana')]],
    ]]);
    app()->instance(HoldedApi::class, $this->fake);
});

it('--dry-run enseña lo que importaría y no crea nada (ni escribe en Holded)', function () {
    $this->artisan('app:import-holded-recurring', ['--dry-run' => true])
        ->expectsOutputToContain('Se importaría en pausa')
        ->expectsOutputToContain('el contacto «Estudio Nébula, S.L.» no está casado con ningún cliente')
        ->expectsOutputToContain('No se entiende la periodicidad (week)')
        ->expectsOutputToContain('Prueba (--dry-run): no se ha creado nada.')
        ->assertSuccessful();

    expect(RecurringInvoice::query()->count())->toBe(0)
        // Solo una lectura del listado de recurrentes.
        ->and($this->fake->requestCount())->toBe(1);
});

it('las crea en pausa con su cliente, líneas, periodicidad, próxima fecha y el fee; lo que no casa, no', function () {
    $this->artisan('app:import-holded-recurring')
        ->expectsOutputToContain('Importadas 2 recurrentes en pausa; 0 ya estaban y 2 no se han podido importar.')
        ->expectsOutputToContain('el servicio «MANT» no está en el catálogo')
        ->assertSuccessful();

    $fee = RecurringInvoice::query()->where('holded_recurring_id', 'r-fee')->firstOrFail();
    expect($fee->isPaused())->toBeTrue()
        ->and($fee->name)->toBe('Fee Arrieta')
        ->and($fee->client_id)->toBe($this->client->id)
        ->and($fee->project_id)->toBe($this->fee->id)
        ->and($fee->periodicity->value)->toBe('monthly')
        ->and($fee->day_of_month)->toBe(29)
        ->and($fee->next_run_on->toDateString())->toBe('2026-10-29')
        ->and($fee->mode)->toBe('draft')
        ->and($fee->reviewed_at)->toBeNull()
        ->and($fee->lines)->toHaveCount(1)
        ->and($fee->lines->first()->description)->toBe('Sprint de [mes] [año]')
        ->and($fee->lines->first()->service_id)->toBe(CatalogService::query()->value('id'))
        ->and((string) $fee->lines->first()->unit_price)->toBe('1200.0000');

    $quarter = RecurringInvoice::query()->where('holded_recurring_id', 'r-quarter')->firstOrFail();
    expect($quarter->periodicity->value)->toBe('quarterly')
        ->and($quarter->interval_months)->toBe(3)
        ->and($quarter->next_run_on->toDateString())->toBe('2027-01-15')
        ->and($quarter->project_id)->toBeNull()
        // Sin el servicio en el catálogo: va sin servicio, con su nombre en la descripción.
        ->and($quarter->lines->first()->service_id)->toBeNull()
        ->and($quarter->lines->first()->description)->toBe("Mantenimiento\nMantenimiento del [trimestre]");

    expect(RecurringInvoice::query()->whereIn('holded_recurring_id', ['r-nebula', 'r-weekly'])->count())->toBe(0);

    // En pausa, la tarea diaria no genera nada aunque llegue su fecha.
    $this->travelTo(CarbonImmutable::parse('2026-10-29 07:30:00', 'Europe/Madrid'));
    $this->artisan('app:billing-generate-recurring')->assertSuccessful();
    expect(SalesDocument::query()->count())->toBe(0);

    // Una segunda vez: ya estaban.
    $this->artisan('app:import-holded-recurring')
        ->expectsOutputToContain('Importadas 0 recurrentes en pausa; 2 ya estaban')
        ->assertSuccessful();
    expect(RecurringInvoice::query()->count())->toBe(2);
});
