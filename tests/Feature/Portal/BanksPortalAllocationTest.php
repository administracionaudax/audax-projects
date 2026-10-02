<?php

use App\Domain\HourBanks\Events\HourBankOverageRecorded;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
use App\Domain\Portal\PortalBankFigures;
use App\Domain\Portal\PortalScope;
use App\Domain\Reports\Pdf\HourBankStatement;
use App\Enums\HourBankStatus;
use App\Enums\TimeEntryStatus;
use App\Models\Client;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use App\Models\User;
use App\Notifications\Portal\ClientHourBankThreshold;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Dentro y exceso en el portal (D-092), calculado a mano. El libro (HourBankLedger) reparte el
| exceso entre TODAS las horas de la bolsa; el portal lo reparte de nuevo SOLO entre las que ve el
| cliente, en orden cronológico y respetando el exceso fijo de las bloqueadas. Inicio, detalle,
| listado, consumo por mes, estado, PDF y avisos cuadran siempre.
|
| Bolsa de 600 min (10:00); hoy, 25/09/2026.
*/

beforeEach(function () {
    Event::fake([HourBankThresholdReached::class, HourBankOverageRecorded::class]);
    $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00:00', 'Europe/Madrid'));
    TaskStatus::ensureDefaults();

    $this->client = Client::factory()->create();
    $this->portal = User::factory()->portalOf($this->client)->create();
    $this->worker = userWithRole('employee');
    $this->project = Project::factory()->hourBank()->create(['client_id' => $this->client->id, 'code' => 'LUR-WEB']);
    $this->bank = HourBank::factory()->create(['project_id' => $this->project->id, 'total_minutes' => 600, 'start_date' => '2026-01-01']);
    $this->task = Task::factory()->inBank($this->bank)->create(['title' => 'Diseño']);

    $this->entry = fn (string $date, int $minutes, TimeEntryStatus $status) => TimeEntry::factory()
        ->forTask($this->task)->on($date)->minutes($minutes)->status($status)
        ->create(['user_id' => $this->worker->id, 'description' => "Horas del {$date}"]);

    $this->props = function (TestResponse $response): array {
        $props = [];
        $response->assertOk()->assertInertia(function (Assert $page) use (&$props): void {
            $props = $page->toArray()['props'];
        });

        return $props;
    };

    // Lo que ve el cliente en el inicio, el detalle (con su listado y sus meses) y el PDF.
    $this->everywhere = function (): array {
        $home = ($this->props)($this->actingAs($this->portal)->get('/portal'));
        $detail = ($this->props)($this->actingAs($this->portal)->get("/portal/bolsas/{$this->bank->id}"));
        $pdf = app(HourBankStatement::class)->forPortal(PortalScope::for($this->portal), $this->bank->fresh());

        return [$home, $detail, $pdf];
    };
});

it('un borrador anterior que llena la bolsa no deja en exceso las horas que ve el cliente', function () {
    ($this->entry)('2026-09-01', 480, TimeEntryStatus::Draft);
    $approved = ($this->entry)('2026-09-15', 300, TimeEntryStatus::Approved);

    // Por dentro, el borrador va primero: la aprobada lleva 180 de exceso y la bolsa está agotada.
    expect($approved->fresh()->overage_minutes)->toBe(180)
        ->and($this->bank->fresh()->status)->toBe(HourBankStatus::Exhausted);

    [$home, $detail, $pdf] = ($this->everywhere)();
    $figures = ['total_minutes' => 600, 'within_minutes' => 300, 'overage_minutes' => 0, 'remaining_minutes' => 300, 'percent' => 0.5];

    expect($home['banks'][0]['figures'])->toBe($figures)
        ->and($home['banks'][0]['status'])->toBe('active')
        ->and($home['summary']['month_minutes'])->toBe(300)
        ->and($home['summary']['month_overage_minutes'])->toBe(0)
        ->and($detail['bank']['figures'])->toBe($figures)
        ->and($detail['bank']['status'])->toBe('active')
        ->and($detail['months'])->toBe([['month' => '2026-09-01', 'within_minutes' => 300, 'overage_minutes' => 0]])
        ->and($detail['entries']['data'][0]['id'])->toBe($approved->id)
        ->and($detail['entries']['data'][0]['overage_minutes'])->toBe(0)
        ->and($pdf['figures'])->toBe(['consumed' => 300, 'in_bank' => 300, 'overage' => 0, 'pending_in_bank' => 0, 'pending_overage' => 0, 'remaining' => 300, 'ratio' => 0.5])
        ->and($pdf['bank']['status'])->toBe(HourBankStatus::Active->label())
        ->and($pdf['entries'][0]['in_bank'])->toBe(300)
        ->and($pdf['entries'][0]['overage'])->toBe(0);
});

it('entre las horas que ve, la que cruza el límite queda con la parte que no cabe como exceso', function () {
    ($this->entry)('2026-09-01', 120, TimeEntryStatus::Draft);
    $first = ($this->entry)('2026-09-02', 400, TimeEntryStatus::Approved);
    $second = ($this->entry)('2026-09-10', 300, TimeEntryStatus::Approved);

    [, $detail, $pdf] = ($this->everywhere)();

    // Dentro 400 + 200; exceso 100 de la segunda (por dentro, 220 por el borrador).
    expect($second->fresh()->overage_minutes)->toBe(220)
        ->and($detail['bank']['figures'])->toBe(['total_minutes' => 600, 'within_minutes' => 600, 'overage_minutes' => 100, 'remaining_minutes' => 0, 'percent' => 1.1667])
        ->and($detail['bank']['status'])->toBe('exhausted')
        ->and(collect($detail['entries']['data'])->pluck('overage_minutes', 'id')->all())->toBe([$second->id => 100, $first->id => 0])
        ->and(array_column($pdf['entries'], 'overage'))->toBe([0, 100]);
});

it('las bloqueadas conservan su exceso fijo y reservan lo que llevan dentro (D-019, D-053)', function () {
    ($this->entry)('2026-09-01', 480, TimeEntryStatus::Draft);
    $locked = ($this->entry)('2026-09-15', 300, TimeEntryStatus::Approved);
    // Se bloquea (al facturar) con los 180 de exceso que tenía: ya no cambian.
    $locked->forceFill(['status' => TimeEntryStatus::Locked])->save();
    $later = ($this->entry)('2026-09-20', 100, TimeEntryStatus::Approved);

    expect($locked->fresh()->overage_minutes)->toBe(180);

    [$home, $detail, $pdf] = ($this->everywhere)();

    // La bloqueada: 120 dentro y 180 de exceso fijo. Caben 480 más: la del 20/09 va toda dentro.
    $figures = ['total_minutes' => 600, 'within_minutes' => 220, 'overage_minutes' => 180, 'remaining_minutes' => 380, 'percent' => 0.6667];
    expect($home['banks'][0]['figures'])->toBe($figures)
        ->and($detail['bank']['figures'])->toBe($figures)
        ->and(collect($detail['entries']['data'])->pluck('overage_minutes', 'id')->all())->toBe([$later->id => 0, $locked->id => 180])
        ->and($pdf['figures']['in_bank'])->toBe(220)
        ->and($pdf['figures']['overage'])->toBe(180)
        ->and(array_column($pdf['entries'], 'overage'))->toBe([180, 0]);
});

it('barra, listado, meses y PDF suman lo mismo, también filtrando por mes y paginando', function () {
    ($this->entry)('2026-07-01', 200, TimeEntryStatus::Submitted);
    foreach (range(1, 30) as $day) {
        ($this->entry)(sprintf('2026-%02d-%02d', $day <= 15 ? 8 : 9, ($day % 15) + 1), 25, TimeEntryStatus::Approved);
    }

    $scope = PortalScope::for($this->portal);
    $figures = PortalBankFigures::one($scope, $this->bank);
    $pdf = app(HourBankStatement::class)->forPortal($scope, $this->bank->fresh());
    $pages = collect([1, 2])->flatMap(fn (int $page) => ($this->props)($this->actingAs($this->portal)->get("/portal/bolsas/{$this->bank->id}?pagina={$page}"))['entries']['data']);
    $september = ($this->props)($this->actingAs($this->portal)->get("/portal/bolsas/{$this->bank->id}?mes=2026-09"));
    $months = collect(PortalBankFigures::byMonth($scope, $this->bank))->keyBy('month');

    // 750 visibles: 600 dentro y 150 de exceso (la enviada de julio no se ve ni ocupa saldo).
    expect($figures)->toMatchArray(['within_minutes' => 600, 'overage_minutes' => 150])
        ->and($pages)->toHaveCount(30)
        ->and($pages->sum('overage_minutes'))->toBe(150)
        ->and($pages->sum(fn (array $e) => $e['minutes'] - $e['overage_minutes']))->toBe(600)
        ->and(array_sum(array_column($pdf['entries'], 'overage')))->toBe(150)
        ->and(array_sum(array_column($pdf['entries'], 'in_bank')))->toBe(600)
        ->and($months->sum('overage_minutes'))->toBe(150)
        ->and(collect($september['entries']['data'])->sum('overage_minutes'))->toBe($months['2026-09-01']['overage_minutes']);
});

it('el aviso al cliente lleva las cifras que ve en el portal', function () {
    Notification::fake();
    $this->client->update(['portal_notify_thresholds' => true]);

    ($this->entry)('2026-09-01', 480, TimeEntryStatus::Draft);
    ($this->entry)('2026-09-15', 560, TimeEntryStatus::Approved);

    // 560 de 600 (93 %): el cliente los ve todos dentro y le quedan 40.
    Notification::assertSentToTimes($this->portal, ClientHourBankThreshold::class, 1);
    Notification::assertSentTo($this->portal, ClientHourBankThreshold::class, fn (ClientHourBankThreshold $n) => $n->threshold === 90
        && $n->figures['within_minutes'] === 560
        && $n->figures['overage_minutes'] === 0
        && $n->figures['remaining_minutes'] === 40);
});
