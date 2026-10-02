<?php

use App\Domain\Portal\PortalBankFigures;
use App\Domain\Portal\PortalScope;
use App\Enums\HourBankStatus;
use App\Enums\PortalEntryVisibility;
use App\Enums\PortalPersonDisplay;
use App\Enums\TimeEntryStatus;
use App\Http\Controllers\Portal\Banks\PortalBankController;
use App\Models\HourBank;
use App\Models\Task;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Portal\BanksScenario;

/*
| Detalle de una bolsa en el portal (P1, SPEC §11, D-064) sobre el escenario calculado a mano
| (BanksScenario): cifras, estado, consumo por mes, entradas visibles con la persona según el
| ajuste del cliente, filtro por mes, paginación e histórico de renovaciones del mismo cliente.
*/

beforeEach(function () {
    $this->s = BanksScenario::build($this);
    $this->url = fn (HourBank $bank, string $query = ''): string => "/portal/bolsas/{$bank->id}".$query;

    $this->props = function (TestResponse $response): array {
        $props = [];
        $response->assertInertia(function (Assert $page) use (&$props): void {
            $props = $page->toArray()['props'];
        });

        return $props;
    };
});

it('enseña las cifras, el estado, el consumo por mes, las horas visibles y la cadena de renovaciones', function () {
    $s = $this->s;

    $this->actingAs($s->portal)->get(($this->url)($s->b1))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/banks/show')
            ->where('bank.id', $s->b1->id)
            ->where('bank.name', 'Bolsa Diseño ñ')
            ->where('bank.project', ['code' => 'NAN-WEB', 'name' => 'Web corporativa'])
            ->where('bank.status', 'exhausted')
            ->where('bank.start_date', '2026-07-01')
            // Solo E1, E2 y E5: dentro 600 + 480 + 120 = 1200, exceso 120 (de E5), 110 %.
            ->where('bank.figures', ['total_minutes' => 1200, 'within_minutes' => 1200, 'overage_minutes' => 120, 'remaining_minutes' => 0, 'percent' => 1.1])
            ->where('visibility', 'approved')
            ->where('thresholds', [75, 90, 100])
            ->where('months', [
                ['month' => '2026-09-01', 'within_minutes' => 1080, 'overage_minutes' => 0],
                ['month' => '2026-10-01', 'within_minutes' => 120, 'overage_minutes' => 120],
            ])
            ->where('filters', ['mes' => null])
            ->where('entries.meta.total', 3)
            ->where('entries.data', [
                ['id' => $s->e5->id, 'date' => '2026-10-01', 'task' => 'Maquetación', 'type' => ['name' => 'Maquetación', 'color' => '#179FA5'], 'person' => 'Luis Pérez', 'minutes' => 240, 'overage_minutes' => 120, 'description' => 'Ajustes finales'],
                ['id' => $s->e2->id, 'date' => '2026-09-22', 'task' => 'Maquetación', 'type' => ['name' => 'Maquetación', 'color' => '#179FA5'], 'person' => 'Luis Pérez', 'minutes' => 480, 'overage_minutes' => 0, 'description' => 'Versión móvil'],
                ['id' => $s->e1->id, 'date' => '2026-09-10', 'task' => 'Diseño de la home', 'type' => ['name' => 'Diseño UI', 'color' => '#0171FF'], 'person' => 'Ana García Ruiz', 'minutes' => 600, 'overage_minutes' => 0, 'description' => 'Maquetación de cabecera'],
            ])
            // La cadena B0 → B1, con enlace a cada una y la actual marcada.
            ->has('history', 2)
            ->where('history.0.id', $s->b0->id)
            ->where('history.0.status', 'renewed')
            ->where('history.0.current', false)
            ->where('history.0.figures.within_minutes', 540)
            ->where('history.1.id', $s->b1->id)
            ->where('history.1.current', true));
});

it('las entradas y las cifras cuadran: lo que va dentro y el exceso suman lo mismo que la barra', function () {
    $s = $this->s;
    $props = ($this->props)($this->actingAs($s->portal)->get(($this->url)($s->b1)));
    $entries = collect($props['entries']['data']);
    $months = collect($props['months']);

    expect($entries->sum(fn ($e) => $e['minutes'] - $e['overage_minutes']))->toBe($props['bank']['figures']['within_minutes'])
        ->and($entries->sum('overage_minutes'))->toBe($props['bank']['figures']['overage_minutes'])
        ->and($months->sum('within_minutes'))->toBe($props['bank']['figures']['within_minutes'])
        ->and($months->sum('overage_minutes'))->toBe($props['bank']['figures']['overage_minutes']);
});

it('con el ajuste del cliente también se ven las enviadas; los borradores, nunca', function () {
    $s = $this->s;
    $s->client->update(['portal_entry_visibility' => PortalEntryVisibility::Submitted]);

    $this->actingAs($s->portal)->get(($this->url)($s->b1))
        ->assertInertia(fn (Assert $page) => $page
            ->where('visibility', 'submitted')
            ->where('bank.figures', ['total_minutes' => 1200, 'within_minutes' => 1200, 'overage_minutes' => 300, 'remaining_minutes' => 0, 'percent' => 1.25])
            ->where('months.1', ['month' => '2026-10-01', 'within_minutes' => 120, 'overage_minutes' => 300])
            ->where('entries.meta.total', 4)
            ->where('entries.data.0.id', $s->e3->id)
            ->where('entries.data.0.description', 'Enviada sin aprobar')
            ->where('entries.data', fn ($data) => ! collect($data)->pluck('id')->contains($s->e4->id)));
});

it('nombra a las personas con el nombre, las iniciales o «Equipo», según el cliente', function (PortalPersonDisplay $display, array $people) {
    $s = $this->s;
    $s->client->update(['portal_person_display' => $display]);

    $props = ($this->props)($this->actingAs($s->portal)->get(($this->url)($s->b1)));

    expect(array_column($props['entries']['data'], 'person'))->toBe($people);

    if ($display === PortalPersonDisplay::Team) {
        // Ni siquiera viajan los nombres.
        expect(json_encode($props, JSON_UNESCAPED_UNICODE))->not->toContain('Luis')->not->toContain('Ana García');
    }
})->with([
    'nombre' => [PortalPersonDisplay::Name, ['Luis Pérez', 'Luis Pérez', 'Ana García Ruiz']],
    'iniciales' => [PortalPersonDisplay::Initials, ['L.P.', 'L.P.', 'A.G.R.']],
    'equipo' => [PortalPersonDisplay::Team, ['Equipo', 'Equipo', 'Equipo']],
]);

it('filtra las entradas por mes (?mes=AAAA-MM) e ignora un valor que no es un mes', function () {
    $s = $this->s;

    $this->actingAs($s->portal)->get(($this->url)($s->b1, '?mes=2026-09'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters', ['mes' => '2026-09'])
            ->where('entries.meta.total', 2)
            ->where('entries.data.0.id', $s->e2->id)
            ->where('entries.data.1.id', $s->e1->id)
            // Las cifras y los meses siguen siendo los de toda la bolsa.
            ->where('bank.figures.within_minutes', 1200)
            ->has('months', 2));

    $this->actingAs($s->portal)->get(($this->url)($s->b1, '?mes=2026-10'))
        ->assertInertia(fn (Assert $page) => $page->where('entries.meta.total', 1)->where('entries.data.0.id', $s->e5->id));

    foreach (['?mes=2026-13', '?mes=septiembre', '?mes[]=2026-09'] as $query) {
        $this->actingAs($s->portal)->get(($this->url)($s->b1, $query))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('filters.mes', null)->where('entries.meta.total', 3));
    }

    expect(PortalBankController::month('2024-02'))->toBe(['value' => '2024-02', 'from' => '2024-02-01', 'to' => '2024-02-29'])
        ->and(PortalBankController::month(null))->toBeNull();
});

it('pagina las entradas de 25 en 25 y conserva el filtro en los enlaces', function () {
    $s = $this->s;
    $task = Task::factory()->inBank($s->b2)->create(['title' => 'Publicaciones']);
    foreach (range(1, 30) as $day) {
        BanksScenario::entry($task, $s->marta, sprintf('2026-09-%02d', $day), 30, TimeEntryStatus::Approved, "Publicación {$day}");
    }

    $this->actingAs($s->portal)->get(($this->url)($s->b2))
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries.meta.total', 31)
            ->where('entries.meta.per_page', PortalBankController::ENTRIES_PER_PAGE)
            ->has('entries.data', 25)
            ->where('entries.links.next', fn (?string $next) => str_contains((string) $next, 'pagina=2')));

    $this->actingAs($s->portal)->get(($this->url)($s->b2, '?mes=2026-09&pagina=2'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries.meta.total', 30)
            ->where('entries.meta.current_page', 2)
            ->has('entries.data', 5)
            ->where('entries.links.prev', fn (?string $prev) => str_contains((string) $prev, 'mes=2026-09') && str_contains((string) $prev, 'pagina=1')));
});

it('la cadena de renovaciones solo lleva bolsas del mismo cliente', function () {
    $s = $this->s;
    // Un dato imposible desde la app: una bolsa de otro cliente que «renueva» a B1.
    $s->foreign->forceFill(['renewed_from_id' => $s->b1->id])->save();

    $this->actingAs($s->portal)->get(($this->url)($s->b1))
        ->assertInertia(fn (Assert $page) => $page
            ->has('history', 2)
            ->where('history.1.id', $s->b1->id));

    // Una bolsa sin renovaciones no tiene cadena.
    $this->actingAs($s->portal)->get(($this->url)($s->b2))
        ->assertInertia(fn (Assert $page) => $page->where('history', []));
});

it('el estado que ve el cliente cuadra con sus cifras aunque por dentro la bolsa vaya más avanzada', function () {
    $s = $this->s;
    // Un borrador de 2700 min agota B2 por dentro (300 + 2700 = 3000), pero el cliente ve 300.
    BanksScenario::entry($s->t4, $s->marta, '2026-10-14', 2700, TimeEntryStatus::Draft, 'Borrador grande');

    expect($s->b2->refresh()->status)->toBe(HourBankStatus::Exhausted);

    $this->actingAs($s->portal)->get(($this->url)($s->b2))
        ->assertInertia(fn (Assert $page) => $page
            ->where('bank.status', 'active')
            ->where('bank.figures.within_minutes', 300));

    // Las cerradas y renovadas conservan su estado; una abierta se agota con lo que ve el cliente.
    $scope = PortalScope::for($s->portal);
    expect(PortalBankFigures::status($s->b0, PortalBankFigures::one($scope, $s->b0)))->toBe(HourBankStatus::Renewed)
        ->and(PortalBankFigures::status($s->b3, PortalBankFigures::one($scope, $s->b3)))->toBe(HourBankStatus::Closed)
        ->and(PortalBankFigures::status($s->b1, PortalBankFigures::one($scope, $s->b1)))->toBe(HourBankStatus::Exhausted)
        ->and(PortalBankFigures::status($s->b2, PortalBankFigures::one($scope, $s->b2)))->toBe(HourBankStatus::Active);
});

it('una bolsa cerrada enseña cuándo se cerró y su saldo restante con lo que ve el cliente', function () {
    $s = $this->s;

    $this->actingAs($s->portal)->get(($this->url)($s->b3))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('bank.status', 'closed')
            ->where('bank.end_date', '2025-12-31')
            ->where('bank.closed_at', fn (?string $closedAt) => $closedAt !== null && str_ends_with($closedAt, 'Z'))
            ->where('bank.figures', ['total_minutes' => 600, 'within_minutes' => 450, 'overage_minutes' => 0, 'remaining_minutes' => 150, 'percent' => 0.75])
            ->where('months', [['month' => '2025-11-01', 'within_minutes' => 450, 'overage_minutes' => 0]]));
});

it('nunca lleva importes, tarifas, costes, notas internas ni la referencia de factura', function () {
    $s = $this->s;
    $json = json_encode(($this->props)($this->actingAs($s->portal)->get(($this->url)($s->b1))), JSON_UNESCAPED_UNICODE);

    expect($json)
        ->not->toContain('price')
        ->not->toContain('rate')
        ->not->toContain('cost')
        ->not->toContain('snapshot')
        ->not->toContain('invoice')
        ->not->toContain('notes')
        ->not->toContain('1000.00')
        ->not->toContain('75.00')
        ->not->toContain('31.50')
        ->not->toContain('FAC-2026-017')
        ->not->toContain('Nota interna')
        ->not->toContain('€')
        ->not->toContain('Borrador que no se ve');
});
