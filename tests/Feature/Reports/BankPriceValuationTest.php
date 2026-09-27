<?php

use App\Domain\HourBanks\Events\HourBankOverageRecorded;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
use App\Domain\Reports\Dimension;
use App\Domain\Reports\EntryValuation;
use App\Domain\Reports\Metrics;
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
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/*
| Bolsas con precio (D-043), calculado a mano:
|  - BIZ-01: el exceso de una entrada aprobada o bloqueada se valora a su tarifa congelada, no a la
|    vigente, en los informes (RevenueCalculator), en cada entrada (EntryValuation) y en las
|    exportaciones de horas y para facturar, que cuadran con el informe,
|  - BIZ-06 (D-082): la parte proporcional del precio nunca supera el precio: si lo que va dentro
|    de la bolsa supera su total (bloqueadas que no cambian al reducir el total, D-054), el precio
|    se reparte entre todo lo de dentro.
| Septiembre de 2026 (hoy, 25/09).
*/

beforeEach(function () {
    Event::fake([HourBankThresholdReached::class, HourBankOverageRecorded::class]);
    $this->travelTo(CarbonImmutable::parse('2026-09-25 12:00', 'Europe/Madrid'));

    $this->admin = User::factory()->admin()->create();
    $this->ana = User::factory()->employee()->create(['name' => 'Ana', 'hourly_cost' => '20.00']);
    $this->luis = User::factory()->employee()->create(['name' => 'Luis', 'hourly_cost' => '30.00']);
    $this->client = Client::factory()->create(['default_hourly_rate' => '60.00']);
    $this->project = Project::factory()->hourBank()->create(['client_id' => $this->client->id, 'code' => 'BOL']);

    $this->month = fn (array $query = []): ReportScope => new ReportScope($this->admin, ReportFilters::fromQuery(['periodo' => 'mes', 'fecha' => '2026-09-01', ...$query]));
    $this->readXlsx = function (string $content): array {
        $path = tempnam(sys_get_temp_dir(), 'bank').'.xlsx';
        file_put_contents($path, $content);
        $reader = new XlsxReader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();
        unlink($path);

        return $rows;
    };
});

/**
 * Bolsa de 600 min por 1000 € a 70 €/h: Ana 500 min aprobados (instantánea 55) el 10, Ana 200 min
 * aprobados (instantánea 55; 100 dentro y 100 de exceso) el 20 y Luis 60 min en borrador (todo
 * exceso) el 22.
 */
function bankWithApprovedOverage(object $test): HourBank
{
    $bank = HourBank::factory()->create(['project_id' => $test->project->id, 'total_minutes' => 600, 'price_amount' => '1000.00', 'hourly_rate' => '70.00', 'start_date' => '2026-09-01']);
    $task = Task::factory()->inBank($bank)->create(['title' => 'Soporte']);
    $approved = ['status' => TimeEntryStatus::Approved, 'hourly_rate_snapshot' => '55.00', 'hourly_cost_snapshot' => '20.00'];
    TimeEntry::factory()->forTask($task)->on('2026-09-10')->minutes(500)->create(['user_id' => $test->ana->id, ...$approved]);
    TimeEntry::factory()->forTask($task)->on('2026-09-20')->minutes(200)->create(['user_id' => $test->ana->id, ...$approved]);
    TimeEntry::factory()->forTask($task)->on('2026-09-22')->minutes(60)->create(['user_id' => $test->luis->id]);

    return $bank->refresh();
}

it('BIZ-01: el exceso de una entrada aprobada va a su tarifa congelada en el informe y en cada entrada', function () {
    $bank = bankWithApprovedOverage($this);
    $scope = ($this->month)();

    expect(TimeEntry::query()->orderBy('date')->pluck('overage_minutes')->all())->toBe([0, 100, 60])
        // 1000 × 600/600 + 100 × 55/60 (Ana, aprobada) + 60 × 70/60 (Luis, borrador) = 1161,67 €
        // (antes, todo el exceso a la tarifa vigente: 160 × 70/60 → 1186,67 €).
        ->and(app(RevenueCalculator::class)->compute($scope->entries())['all']['income'])->toBe('1161.67')
        ->and(app(Metrics::class)->summary($scope, withCapacity: false)['income'])->toBe('1161.67');

    $valuation = EntryValuation::for($scope->entries());
    $entries = TimeEntry::query()->orderBy('date')->get();

    // 1000 × 100/600 + 100 × 55/60 = 258,333…; la de Luis, 60 × 70/60 = 70.
    expect($valuation->value($entries[1]))->toBe(['rate' => '55.00', 'income' => '258.333332', 'basis' => EntryValuation::BANK_PRICE])
        ->and($valuation->value($entries[2]))->toBe(['rate' => '70.00', 'income' => '70.000000', 'basis' => EntryValuation::BANK_PRICE])
        ->and($bank->overage_minutes)->toBe(160);
});

it('BIZ-01: la exportación de horas y la de facturar cuadran con el informe', function () {
    bankWithApprovedOverage($this);

    $hours = ($this->readXlsx)($this->actingAs($this->admin)
        ->get('/informes/horas/exportar?periodo=mes&fecha=2026-09-01&formato=xlsx')->assertOk()->streamedContent());
    $headers = array_shift($hours);
    $rows = array_map(fn (array $row): array => array_combine($headers, array_pad($row, count($headers), '')), $hours);

    expect(array_column($rows, 'Tarifa (€/h)'))->toBe([55, 55, 70])
        ->and(array_column($rows, 'Ingreso estimado (€)'))->toBe([833.33, 258.34, 70])
        ->and(round(array_sum(array_column($rows, 'Ingreso estimado (€)')), 2))->toBe(1161.67);

    $billing = ($this->readXlsx)($this->actingAs($this->admin)
        ->get('/informes/facturacion?periodo=mes&fecha=2026-09-01&formato=xlsx&cliente[]='.$this->client->id)->assertOk()->streamedContent());
    $amount = array_search('Importe (€)', $billing[0], true);

    expect(array_column(array_slice($billing, 1, -1), $amount))->toBe([833.33, 258.34, 70])
        ->and(end($billing)[$amount])->toBe(1161.67);

    $this->actingAs($this->admin)
        ->get('/informes/facturacion?periodo=mes&fecha=2026-09-01&cliente[]='.$this->client->id)
        ->assertInertia(fn ($page) => $page->where('summary.totals.income', '1161.67'));
});

/**
 * Bolsa de 900 min por 1000 € con dos entradas bloqueadas de Luis (500 min el 8 y 250 el 15, todo
 * dentro); después su total baja a 600: las bloqueadas no cambian (D-019), así que dentro quedan
 * 750 min, más que el total.
 */
function bankOverItsTotal(object $test): HourBank
{
    $bank = HourBank::factory()->create(['project_id' => $test->project->id, 'total_minutes' => 900, 'price_amount' => '1000.00', 'hourly_rate' => '70.00', 'start_date' => '2026-09-01']);
    $task = Task::factory()->inBank($bank)->create();
    foreach (['2026-09-08' => 500, '2026-09-15' => 250] as $date => $minutes) {
        TimeEntry::factory()->forTask($task)->on($date)->minutes($minutes)->status(TimeEntryStatus::Locked)
            ->create(['user_id' => $test->luis->id, 'hourly_rate_snapshot' => '70.00', 'hourly_cost_snapshot' => '30.00']);
    }
    $bank->refresh()->update(['total_minutes' => 600]);

    return $bank->refresh();
}

it('BIZ-06: la parte del precio de una bolsa nunca supera su precio, aunque lo de dentro supere el total', function () {
    $bank = bankOverItsTotal($this);
    $scope = ($this->month)();

    expect($bank->in_bank_minutes)->toBe(750)
        ->and($bank->overage_minutes)->toBe(0)
        // Antes: 1000 × 750/600 = 1250 €, más que el precio. Ahora: 1000 × 750/750 = 1000 €.
        ->and(app(RevenueCalculator::class)->compute($scope->entries())['all']['income'])->toBe('1000.00');

    // Por semanas, el precio se reparte entre los 750 min: 1000 × 500/750 y 1000 × 250/750.
    $weeks = app(RevenueCalculator::class)->compute($scope->entries(), Dimension::Week);
    expect($weeks['2026-09-07']['income'])->toBe('666.67')
        ->and($weeks['2026-09-14']['income'])->toBe('333.33');

    $valuation = EntryValuation::for($scope->entries());
    $entries = TimeEntry::query()->orderBy('date')->get();
    expect($valuation->value($entries[0])['income'])->toBe('666.666666')
        ->and($valuation->value($entries[1])['income'])->toBe('333.333333');
});

it('BIZ-06: en una bolsa normal (lo de dentro no supera el total) el reparto no cambia', function () {
    bankWithApprovedOverage($this);

    // 1000 × 500/600 = 833,33 (la primera entrada, la bolsa aún sin exceso).
    $scope = ($this->month)(['periodo' => 'rango', 'desde' => '2026-09-01', 'hasta' => '2026-09-12']);
    expect(app(RevenueCalculator::class)->compute($scope->entries())['all']['income'])->toBe('833.33');
});
