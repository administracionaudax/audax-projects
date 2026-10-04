<?php

use App\Domain\Portal\PortalBankFigures;
use App\Domain\Portal\PortalScope;
use App\Domain\Reports\Pdf\HourBankStatement;
use App\Domain\Reports\Pdf\HourBankStatementView;
use App\Domain\Reports\Pdf\ReportHtml;
use App\Enums\HourBankStatus;
use App\Enums\PortalEntryVisibility;
use App\Enums\PortalPersonDisplay;
use App\Models\HourBank;
use Tests\Feature\Portal\BanksScenario;

/*
| El PDF de consumo de la Fase 2 en modo portal (P1, D-066): HourBankStatement::forPortal y los
| textos del portal en HourBankStatementView (Fase 9, D-140), sobre el escenario calculado a mano
| (BanksScenario). Solo las horas que ve el cliente según su ajuste, las personas como las ve él,
| NUNCA importes y las mismas cifras que PortalBankFigures. Se lee el texto del HTML del PDF.
*/

beforeEach(function () {
    $this->s = BanksScenario::build($this);

    // El texto del PDF de una bolsa, tal como lo ve la usuaria del portal.
    $this->pdf = function (HourBank $bank): string {
        $statement = app(HourBankStatement::class)->forPortal(PortalScope::for($this->s->portal->fresh()), $bank);

        return reportHtmlText(app(ReportHtml::class)->render(HourBankStatementView::make($statement, '', 'prueba')));
    };
});

it('sus cifras, sus meses y su listado cuadran con PortalBankFigures, sin importes', function (PortalEntryVisibility $visibility) {
    $s = $this->s;
    $s->client->update(['portal_entry_visibility' => $visibility]);
    $scope = PortalScope::for($s->portal->fresh());

    $statement = app(HourBankStatement::class)->forPortal($scope, $s->b1);
    $figures = PortalBankFigures::one($scope, $s->b1);

    expect($statement['figures'])->toBe([
        'consumed' => $figures['within_minutes'] + $figures['overage_minutes'],
        'in_bank' => $figures['within_minutes'],
        'overage' => $figures['overage_minutes'],
        'pending_in_bank' => 0,
        'pending_overage' => 0,
        'remaining' => $figures['remaining_minutes'],
        'ratio' => $figures['percent'],
    ])
        ->and($statement['months'])->toBe(array_map(fn (array $m): array => ['month' => $m['month'], 'in_bank' => $m['within_minutes'], 'overage' => $m['overage_minutes']], PortalBankFigures::byMonth($scope, $s->b1)))
        ->and(array_sum(array_column($statement['entries'], 'in_bank')))->toBe($figures['within_minutes'])
        ->and(array_sum(array_column($statement['entries'], 'overage')))->toBe($figures['overage_minutes'])
        ->and($statement['bank']['total_minutes'])->toBe(1200)
        ->and($statement['bank']['status'])->toBe(HourBankStatus::Exhausted->label())
        ->and($statement['client'])->toBe('Bodega Ñandú')
        ->and($statement['financials'])->toBeNull()
        ->and($statement['partial'])->toBeFalse()
        ->and($statement['portal'])->toBe(['visibility' => $visibility->value]);
})->with([
    'aprobadas' => [PortalEntryVisibility::Approved],
    'también enviadas' => [PortalEntryVisibility::Submitted],
]);

it('cifras calculadas a mano: solo aprobadas y bloqueadas, con la persona por su nombre', function () {
    $s = $this->s;
    $statement = app(HourBankStatement::class)->forPortal(PortalScope::for($s->portal), $s->b1);

    // E1, E2 y E5: 1320 consumidos, 1200 dentro, 120 de exceso (de E5) y 0 restantes; 110 %.
    expect($statement['figures'])->toBe(['consumed' => 1320, 'in_bank' => 1200, 'overage' => 120, 'pending_in_bank' => 0, 'pending_overage' => 0, 'remaining' => 0, 'ratio' => 1.1])
        ->and($statement['months'])->toBe([
            ['month' => '2026-09-01', 'in_bank' => 1080, 'overage' => 0],
            ['month' => '2026-10-01', 'in_bank' => 120, 'overage' => 120],
        ])
        ->and($statement['entries'])->toBe([
            ['date' => '2026-09-10', 'person' => 'Ana García Ruiz', 'task' => 'Diseño de la home', 'in_bank' => 600, 'overage' => 0, 'description' => 'Maquetación de cabecera'],
            ['date' => '2026-09-22', 'person' => 'Luis Pérez', 'task' => 'Maquetación', 'in_bank' => 480, 'overage' => 0, 'description' => 'Versión móvil'],
            ['date' => '2026-10-01', 'person' => 'Luis Pérez', 'task' => 'Maquetación', 'in_bank' => 120, 'overage' => 120, 'description' => 'Ajustes finales'],
        ]);
});

it('lleva la bolsa, las cifras que ve el cliente, el consumo por mes y sus horas, con acentos y eñes', function () {
    $pdf = ($this->pdf)($this->s->b1);
    $has = fn (string $utf8) => expect($pdf)->toContain($utf8);

    $has('Consumo de la bolsa de horas');
    $has('Bodega Ñandú');
    $has('NAN-WEB · Web corporativa');
    $has('Bolsa Diseño ñ');
    $has('Desde el 01/07/2026');
    $has('Agotada');
    // 1320 (22:00) de 1200 (20:00): dentro 20:00 y +2:00 de exceso, 110 %.
    $has('Horas aprobadas');
    $has('20:00');
    $has('22:00');
    $has('110 % de la bolsa');
    $has('+2:00');
    $has('Saldo restante: 0:00');
    $has('Septiembre de 2026');
    $has('Octubre de 2026');
    $has('18:00');
    $has('Solo incluye las horas ya aprobadas a fecha de 15/10/2026. Las horas en curso aparecerán cuando se aprueben.');
    $has('Maquetación de cabecera');
    $has('Versión móvil');
    $has('Ajustes finales');
    $has('Ana García Ruiz');
    $has('Luis Pérez');
    $has('10/09/2026');
    expect($pdf)
        // Nunca la enviada ni el borrador, ni «sin aprobar» aparte (D-095): las cifras son las del cliente.
        ->not->toContain('Enviada sin aprobar')
        ->not->toContain('Borrador que no se ve')
        ->not->toContain('Sin aprobar')
        ->not->toContain('Horas de otro cliente');
});

it('nunca lleva importes, tarifas, costes ni notas internas', function () {
    $pdf = ($this->pdf)($this->s->b1);

    expect($pdf)
        ->not->toContain('€')
        ->not->toContain('Datos económicos')
        ->not->toContain('Tarifa')
        ->not->toContain('Precio')
        ->not->toContain('Ingreso')
        ->not->toContain('1.000,00')
        ->not->toContain('75,00')
        ->not->toContain('31,50')
        ->not->toContain('FAC-2026-017')
        ->not->toContain('Nota interna');
});

it('con las enviadas lo dice, las lista y cambia la etiqueta de las horas; los borradores nunca', function () {
    $s = $this->s;
    $s->client->update(['portal_entry_visibility' => PortalEntryVisibility::Submitted]);
    $pdf = ($this->pdf)($s->b1);
    $has = fn (string $utf8) => expect($pdf)->toContain($utf8);

    $has('Horas enviadas y aprobadas');
    $has('Incluye las horas enviadas y las ya aprobadas a fecha de 15/10/2026. Las horas en borrador no aparecen.');
    $has('Enviada sin aprobar');
    // 1500 (25:00): dentro 20:00 y +5:00 de exceso, 125 %.
    $has('25:00');
    $has('+5:00');
    $has('125 % de la bolsa');
    expect($pdf)->not->toContain('Borrador que no se ve');
});

it('las personas salen como las ve el cliente: iniciales o «Equipo»', function () {
    $s = $this->s;

    $s->client->update(['portal_person_display' => PortalPersonDisplay::Initials]);
    expect(($this->pdf)($s->b1))->toContain('L.P.')
        ->toContain('A.G.R.')
        ->not->toContain('Luis Pérez')
        ->not->toContain('Ana García');

    $s->client->update(['portal_person_display' => PortalPersonDisplay::Team]);
    expect(($this->pdf)($s->b1))->toContain('Equipo')
        ->not->toContain('Luis')
        ->not->toContain('Ana García');
});

it('una bolsa sin horas visibles lo dice en lugar del listado', function () {
    $s = $this->s;
    $empty = HourBank::factory()->create(['project_id' => $s->mkt->id, 'name' => 'Bolsa vacía', 'total_minutes' => 600, 'start_date' => '2026-10-01']);

    $statement = app(HourBankStatement::class)->forPortal(PortalScope::for($s->portal), $empty);
    $pdf = ($this->pdf)($empty);

    expect($statement['entries'])->toBe([])
        ->and($statement['months'])->toBe([])
        ->and($statement['figures']['remaining'])->toBe(600)
        ->and($pdf)->toContain('Todavía no hay horas que mostrar en esta bolsa.')
        ->and($pdf)->toContain('Activa');
});

it('el PDF interno de la Fase 2 no cambia: sus textos siguen siendo los de siempre', function () {
    $s = $this->s;
    $statement = app(HourBankStatement::class)->build(userWithRole('admin'), $s->b1);
    $pdf = reportHtmlText(app(ReportHtml::class)->render(HourBankStatementView::make($statement, '', 'prueba')));

    expect($statement)->not->toHaveKey('portal')
        ->and($pdf)->toContain('Horas aprobadas')
        ->and($pdf)->toContain('Solo incluye las horas aprobadas o bloqueadas a fecha de 15/10/2026.')
        // El interno enseña aparte lo que falta por aprobar (E3 y E4).
        ->and($pdf)->toContain('Sin aprobar');
});
