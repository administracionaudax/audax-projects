<?php

use App\Enums\PortalEntryVisibility;
use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\HourBank;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Portal\BanksScenario;

/*
| Inicio del portal (P1, SPEC §11, D-064) sobre el escenario calculado a mano (BanksScenario): las
| bolsas activas con las cifras que ve el cliente, el resumen, el histórico y la nota de qué horas ve.
*/

beforeEach(function () {
    $this->s = BanksScenario::build($this);

    // Props de la página, tal como llegan al navegador.
    $this->props = function (TestResponse $response): array {
        $props = [];
        $response->assertInertia(function (Assert $page) use (&$props): void {
            $props = $page->toArray()['props'];
        });

        return $props;
    };
});

it('enseña las bolsas activas con las cifras que ve el cliente, el resumen del mes y el histórico', function () {
    $s = $this->s;

    $this->actingAs($s->portal)->get('/portal')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/home')
            ->where('client', ['name' => 'Bodega Ñandú'])
            ->where('visibility', 'approved')
            ->where('thresholds', [75, 90, 100])
            // Octubre: E5 (240, 120 de exceso) y E6 (300); ni la enviada E3, ni el borrador E4, ni
            // las horas sin bolsa de NAN-CAMP, ni las de otro cliente.
            ->where('summary', [
                'month' => '2026-10-01',
                'month_minutes' => 540,
                'month_overage_minutes' => 120,
                'open_count' => 2,
                'near_limit_count' => 1,
                'first_threshold' => 75,
            ])
            // Activas por código de proyecto: NAN-MKT (B2) antes que NAN-WEB (B1).
            ->has('banks', 2)
            ->where('banks.0', [
                'id' => $s->b2->id,
                'name' => 'Bolsa Marketing',
                'project' => ['code' => 'NAN-MKT', 'name' => 'Campañas'],
                'status' => 'active',
                'start_date' => '2026-09-01',
                'end_date' => null,
                'closed_at' => null,
                'figures' => ['total_minutes' => 3000, 'within_minutes' => 300, 'overage_minutes' => 0, 'remaining_minutes' => 2700, 'percent' => 0.1],
            ])
            ->where('banks.1', [
                'id' => $s->b1->id,
                'name' => 'Bolsa Diseño ñ',
                'project' => ['code' => 'NAN-WEB', 'name' => 'Web corporativa'],
                'status' => 'exhausted',
                'start_date' => '2026-07-01',
                'end_date' => null,
                'closed_at' => null,
                'figures' => ['total_minutes' => 1200, 'within_minutes' => 1200, 'overage_minutes' => 120, 'remaining_minutes' => 0, 'percent' => 1.1],
            ])
            // Histórico: la renovada B0 (acaba el 30/06/2026) y la cerrada B3 (31/12/2025).
            ->has('history', 2)
            ->where('history.0.id', $s->b0->id)
            ->where('history.0.status', 'renewed')
            ->where('history.0.figures', ['total_minutes' => 600, 'within_minutes' => 540, 'overage_minutes' => 0, 'remaining_minutes' => 60, 'percent' => 0.9])
            ->where('history.1.id', $s->b3->id)
            ->where('history.1.status', 'closed')
            ->where('history.1.end_date', '2025-12-31')
            ->where('history.1.closed_at', fn (?string $closedAt) => $closedAt !== null)
            ->where('history.1.figures.within_minutes', 450));
});

it('con el ajuste del cliente, también las horas enviadas; los borradores nunca', function () {
    $s = $this->s;
    $s->client->update(['portal_entry_visibility' => PortalEntryVisibility::Submitted]);

    $this->actingAs($s->portal)->get('/portal')
        ->assertInertia(fn (Assert $page) => $page
            ->where('visibility', 'submitted')
            ->where('summary.month_minutes', 720)
            ->where('summary.month_overage_minutes', 300)
            ->where('banks.1.figures', ['total_minutes' => 1200, 'within_minutes' => 1200, 'overage_minutes' => 300, 'remaining_minutes' => 0, 'percent' => 1.25]));
});

it('«cerca del límite» cuenta desde el primer umbral configurado', function () {
    $s = $this->s;
    Setting::set('hour_bank_alert_thresholds', [5, 50, 100]);

    $this->actingAs($s->portal)->get('/portal')
        ->assertInertia(fn (Assert $page) => $page
            ->where('thresholds', [5, 50, 100])
            ->where('summary.first_threshold', 5)
            // B2 va al 10 % (≥ 5 %) y B1 al 100 %.
            ->where('summary.near_limit_count', 2));
});

it('nunca lleva importes, tarifas, costes, notas internas ni datos de otros clientes', function () {
    $s = $this->s;
    $props = ($this->props)($this->actingAs($s->portal)->get('/portal'));
    $json = json_encode($props, JSON_UNESCAPED_UNICODE);

    // Nombres de campo, no texto libre: los nombres aleatorios de las factorías pueden contener
    // «cost» o «rate» (p. ej. «Costa») sin que sea una fuga.
    $keys = [];
    $walk = function (mixed $node) use (&$walk, &$keys): void {
        if (is_array($node)) {
            foreach ($node as $key => $child) {
                $keys[] = (string) $key;
                $walk($child);
            }
        }
    };
    $walk($props);
    $fieldNames = implode(' ', array_unique($keys));

    expect($fieldNames)
        ->not->toContain('price')
        ->not->toContain('rate')
        ->not->toContain('cost')
        ->not->toContain('invoice')
        ->not->toContain('notes');

    expect($json)
        ->not->toContain('1000.00')
        ->not->toContain('75.00')
        ->not->toContain('31.50')
        ->not->toContain('60.00')
        ->not->toContain('FAC-2026-017')
        ->not->toContain('Nota interna')
        ->not->toContain('€')
        ->not->toContain('Bolsa ajena')
        ->not->toContain('OTR-WEB');

    // El otro cliente solo ve la suya.
    $this->actingAs($s->otherPortal)->get('/portal')
        ->assertInertia(fn (Assert $page) => $page
            ->has('banks', 1)
            ->where('banks.0.id', $s->foreign->id)
            ->where('history', [])
            ->where('summary.month_minutes', 60));
});

it('una bolsa abierta de un proyecto archivado pasa al histórico (como en la vista global, D-054)', function () {
    $s = $this->s;
    $s->mkt->update(['status' => ProjectStatus::Archived]);

    $this->actingAs($s->portal)->get('/portal')
        ->assertInertia(fn (Assert $page) => $page
            ->has('banks', 1)
            ->where('banks.0.id', $s->b1->id)
            ->where('summary.open_count', 1)
            ->where('history', fn ($history) => collect($history)->pluck('id')->contains($s->b2->id)));
});

it('sin bolsas, el inicio queda vacío (estado vacío de la página) y no consulta las horas', function () {
    $client = Client::factory()->create(['name' => 'Cliente nuevo']);
    $user = User::factory()->portalOf($client)->create();

    $this->actingAs($user)->get('/portal')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/home')
            ->where('banks', [])
            ->where('history', [])
            ->where('summary', [
                'month' => '2026-10-01',
                'month_minutes' => 0,
                'month_overage_minutes' => 0,
                'open_count' => 0,
                'near_limit_count' => 0,
                'first_threshold' => 75,
            ]));
});

it('una bolsa sin horas visibles sale a cero y en margen', function () {
    $s = $this->s;
    $empty = HourBank::factory()->create(['project_id' => $s->mkt->id, 'name' => 'Bolsa vacía', 'total_minutes' => 600, 'start_date' => '2026-10-01']);

    $banks = collect(($this->props)($this->actingAs($s->portal)->get('/portal'))['banks'])->keyBy('id');

    expect($banks)->toHaveCount(3)
        ->and($banks[$empty->id]['status'])->toBe('active')
        ->and($banks[$empty->id]['figures'])->toEqual([
            'total_minutes' => 600, 'within_minutes' => 0, 'overage_minutes' => 0, 'remaining_minutes' => 600, 'percent' => 0.0,
        ]);
});
