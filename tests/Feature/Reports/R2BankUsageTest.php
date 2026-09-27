<?php

use App\Domain\Reports\BankUsage;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportScope;
use Tests\Feature\Reports\R2Scenario;

/*
| Horas dentro de bolsas por proyecto (BankUsage, R2) frente al escenario calculado a mano
| (R2Scenario): solo las entradas con bolsa y sin su exceso; los proyectos sin bolsa no salen.
*/

beforeEach(function () {
    $this->s = R2Scenario::build($this);
    $this->usage = fn ($viewer, array $query): array => app(BankUsage::class)->byProject(
        new ReportScope($viewer, ReportFilters::fromQuery(['periodo' => 'semana', 'fecha' => '2026-09-21', ...$query])),
    );
});

it('cuenta solo lo que va dentro de las bolsas y el exceso aparte, por proyecto', function () {
    $s = $this->s;

    // Semana del cliente: E1 (200 dentro + 100 de exceso), E2 (400 dentro) y E3 (90 de exceso).
    // NAN-CAMP (por horas) no sale aunque tenga 150 minutos.
    expect(($this->usage)($s->admin, ['cliente' => [$s->client->id]]))->toBe([
        $s->web->id => ['in_bank_minutes' => 600, 'overage_minutes' => 190],
    ]);

    // Agosto: E0, 300 dentro de B0.
    expect(($this->usage)($s->admin, ['periodo' => 'mes', 'fecha' => '2026-08-01']))->toBe([
        $s->web->id => ['in_bank_minutes' => 300, 'overage_minutes' => 0],
    ]);
});

it('respeta los filtros y quién ve qué horas (ReportScope)', function () {
    $s = $this->s;

    // Solo Ana: E1 (200 + 100) y E3 (0 + 90).
    expect(($this->usage)($s->admin, ['persona' => [$s->ana->id]]))->toBe([
        $s->web->id => ['in_bank_minutes' => 200, 'overage_minutes' => 190],
    ]);

    // B0 no tiene horas en la semana.
    expect(($this->usage)($s->admin, ['bolsa' => [$s->b0->id]]))->toBe([]);

    // Olga gestiona NAN-CAMP (sin bolsas) y no ve las horas de NAN-WEB.
    expect(($this->usage)($s->olga, []))->toBe([]);
});
