<?php

use App\Domain\HourBanks\Events\HourBankOverageRecorded;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
use App\Domain\Reports\EntryValuation;
use App\Domain\Reports\Money;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportScope;
use App\Domain\Reports\RevenueCalculator;
use App\Enums\TimeEntryStatus;
use App\Models\Client;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

/*
| Valoración de cada entrada con el criterio de D-043 (EntryValuation, R2), calculada a mano, y
| comprobación de que la suma de las entradas es el ingreso de RevenueCalculator (el mismo que
| enseñan los informes). Mes de septiembre de 2026.
*/

beforeEach(function () {
    Event::fake([HourBankThresholdReached::class, HourBankOverageRecorded::class]);
    $this->travelTo(CarbonImmutable::parse('2026-09-25 12:00', 'Europe/Madrid'));

    $this->admin = User::factory()->admin()->create();
    $ana = User::factory()->employee()->create(['default_hourly_rate' => '50.00', 'hourly_cost' => '20.00']);
    $noRate = User::factory()->employee()->create();
    $client = Client::factory()->create(['default_hourly_rate' => '60.00']);
    $plainClient = Client::factory()->create(['default_hourly_rate' => null]);
    $entry = fn (Task $task, string $date, int $minutes, array $extra = []): TimeEntry => TimeEntry::factory()->forTask($task)->on($date)->minutes($minutes)->create(['user_id' => $ana->id, ...$extra]);

    // Por horas (tarifa del cliente, 60): instantánea 55 → 300 × 55/60 = 275; borrador → 120 × 60/60 = 120.
    $tm = Task::factory()->create(['project_id' => Project::factory()->create(['client_id' => $client->id])->id]);
    $this->snapshot = $entry($tm, '2026-09-22', 300, ['status' => TimeEntryStatus::Approved, 'hourly_rate_snapshot' => '55.00']);
    $this->current = $entry($tm, '2026-09-23', 120);

    // Bolsa con precio (600 min, 1000 €, 70 €/h): 500 → 833,33; 200 (100 de exceso) → 166,67 + 116,67.
    $bank = HourBank::factory()->create(['total_minutes' => 600, 'price_amount' => '1000.00', 'hourly_rate' => '70.00']);
    $bankTask = Task::factory()->inBank($bank)->create();
    $this->inside = $entry($bankTask, '2026-09-22', 500);
    $this->crossing = $entry($bankTask, '2026-09-24', 200);

    // Bolsa sin precio a 40 €/h: 90 × 40/60 = 60.
    $unpriced = HourBank::factory()->create(['total_minutes' => 3000, 'hourly_rate' => '40.00']);
    $this->unpriced = $entry(Task::factory()->inBank($unpriced)->create(), '2026-09-22', 90);

    // Precio cerrado 3000 €, presupuesto 1200: base 1200 → 240 → 600; 60 → 150.
    $fixed = Project::factory()->fixedPrice()->create(['fixed_price_amount' => '3000.00', 'budget_minutes' => 1200]);
    $fixedTask = Task::factory()->create(['project_id' => $fixed->id, 'estimated_minutes' => 600]);
    $this->fixedA = $entry($fixedTask, '2026-09-24', 240);
    $this->fixedB = $entry($fixedTask, '2026-09-10', 60);

    // Interno y no facturable: 0.
    $internal = Task::factory()->create(['project_id' => Project::factory()->internal()->create()->id]);
    $this->internal = $entry($internal, '2026-09-25', 45);
    $this->notBillable = $entry($tm, '2026-09-25', 30, ['is_billable' => false]);

    // Sin tarifa de proyecto ni de cliente: la de la persona (50 €/h) → 30 × 50/60 = 25; sin ninguna: 0.
    $plain = Task::factory()->create(['project_id' => Project::factory()->create(['client_id' => $plainClient->id])->id]);
    $this->personRate = $entry($plain, '2026-09-22', 30);
    $this->noRate = TimeEntry::factory()->forTask($plain)->on('2026-09-22')->minutes(30)->create(['user_id' => $noRate->id]);

    $this->scope = new ReportScope($this->admin, ReportFilters::fromQuery(['periodo' => 'mes', 'fecha' => '2026-09-01']));
});

it('valora cada entrada como a mano, con su tarifa y su criterio', function () {
    $valuation = EntryValuation::for($this->scope->entries());
    $value = fn (TimeEntry $entry): array => ($v = $valuation->value($entry->fresh())) + ['rounded' => Money::round($v['income'])];

    expect($value($this->snapshot))->toMatchArray(['rate' => '55.00', 'rounded' => '275.00', 'basis' => EntryValuation::SNAPSHOT])
        ->and($value($this->current))->toMatchArray(['rate' => '60.00', 'rounded' => '120.00', 'basis' => EntryValuation::RATE])
        ->and($value($this->inside))->toMatchArray(['rate' => '70.00', 'rounded' => '833.33', 'basis' => EntryValuation::BANK_PRICE])
        ->and($value($this->crossing))->toMatchArray(['rate' => '70.00', 'rounded' => '283.33', 'basis' => EntryValuation::BANK_PRICE])
        ->and($value($this->unpriced))->toMatchArray(['rate' => '40.00', 'rounded' => '60.00', 'basis' => EntryValuation::RATE])
        ->and($value($this->fixedA))->toMatchArray(['rate' => null, 'rounded' => '600.00', 'basis' => EntryValuation::FIXED_PRICE])
        ->and($value($this->fixedB))->toMatchArray(['rate' => null, 'rounded' => '150.00', 'basis' => EntryValuation::FIXED_PRICE])
        ->and($value($this->internal))->toMatchArray(['rate' => null, 'rounded' => '0.00', 'basis' => EntryValuation::INTERNAL])
        ->and($value($this->notBillable))->toMatchArray(['rate' => null, 'rounded' => '0.00', 'basis' => EntryValuation::NOT_BILLABLE])
        ->and($value($this->personRate))->toMatchArray(['rate' => '50.00', 'rounded' => '25.00', 'basis' => EntryValuation::RATE])
        ->and($value($this->noRate))->toMatchArray(['rate' => null, 'rounded' => '0.00', 'basis' => EntryValuation::NO_RATE]);
});

it('la suma de las entradas es el ingreso estimado de RevenueCalculator', function () {
    $valuation = EntryValuation::for($this->scope->entries());
    $sum = '0';
    foreach ((clone $this->scope->entries())->get() as $entry) {
        $sum = Money::add($sum, $valuation->value($entry)['income']);
    }

    // 275 + 120 + 833,33 + 283,33 + 60 + 600 + 150 + 25 = 2346,67.
    expect(Money::round($sum))->toBe('2346.67')
        ->and(app(RevenueCalculator::class)->compute($this->scope->entries())['all']['income'])->toBe('2346.67');
});

it('valora igual las filas sin hidratar (valueOf) que los modelos (value)', function () {
    $valuation = EntryValuation::for($this->scope->entries());
    $models = (clone $this->scope->entries())->orderBy('time_entries.id')->get();
    $rows = (clone $this->scope->entries())->toBase()->orderBy('time_entries.id')->get();

    expect($rows)->toHaveCount($models->count());

    foreach ($models as $index => $entry) {
        $row = $rows[$index];

        expect($valuation->valueOf(
            (int) $row->project_id,
            $row->hour_bank_id === null ? null : (int) $row->hour_bank_id,
            (int) $row->user_id,
            (bool) $row->is_billable,
            (int) $row->minutes,
            (int) $row->overage_minutes,
            $row->hourly_rate_snapshot,
        ))->toBe($valuation->value($entry));
    }
});
