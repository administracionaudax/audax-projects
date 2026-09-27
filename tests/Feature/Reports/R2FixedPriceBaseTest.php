<?php

use App\Domain\Reports\Dimension;
use App\Domain\Reports\EntryValuation;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportScope;
use App\Domain\Reports\RevenueCalculator;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/*
| Base de avance del precio cerrado (D-043): el mayor del presupuesto, la suma de las estimaciones
| de las tareas RAÍZ y lo imputado. La estimación de una raíz es la suma de sus subtareas estimadas
| si alguna lo está; si no, la suya (SPEC §6, como ProjectSummary). Calculado a mano:
|
| «Precio cerrado A» 3000 €, sin presupuesto:
|  - P1 estimada en 600 con dos subtareas SIN estimar (y una borrada estimada en 5000) → 600,
|  - P2 estimada en 999 con subtareas de 100 y sin estimar → 100 (la suya no cuenta),
|  - P3 sin subtareas, 200 → 200,
|  - un hito estimado en 50 no cuenta.
|  Base = máx(0, 600 + 100 + 200, 180 imputados) = 900. Ana imputa 180 en una subtarea de P1:
|  3000 × 180/900 = 600,00 € (con la regla de las hojas la base habría sido 300 y el ingreso 1800 €).
| «Precio cerrado B» 600 €, presupuesto 50: raíz de 120 con una subtarea de 60 → 60; imputados 30.
|  Base = máx(50, 60, 30) = 60 → 600 × 30/60 = 300,00 €.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-25 12:00', 'Europe/Madrid'));
    $this->admin = User::factory()->admin()->create();
    $ana = User::factory()->employee()->create();

    $this->a = Project::factory()->fixedPrice()->create(['fixed_price_amount' => '3000.00', 'budget_minutes' => null, 'name' => 'Precio cerrado A']);
    $p1 = Task::factory()->create(['project_id' => $this->a->id, 'title' => 'P1', 'estimated_minutes' => 600]);
    $p1a = Task::factory()->subtaskOf($p1)->create(['estimated_minutes' => null]);
    Task::factory()->subtaskOf($p1)->create(['estimated_minutes' => null]);
    Task::factory()->subtaskOf($p1)->create(['estimated_minutes' => 5000])->delete();
    $p2 = Task::factory()->create(['project_id' => $this->a->id, 'title' => 'P2', 'estimated_minutes' => 999]);
    Task::factory()->subtaskOf($p2)->create(['estimated_minutes' => 100]);
    Task::factory()->subtaskOf($p2)->create(['estimated_minutes' => null]);
    Task::factory()->create(['project_id' => $this->a->id, 'title' => 'P3', 'estimated_minutes' => 200]);
    Task::factory()->milestone()->create(['project_id' => $this->a->id, 'estimated_minutes' => 50]);
    $this->entryA = TimeEntry::factory()->forTask($p1a)->on('2026-09-22')->minutes(180)->create(['user_id' => $ana->id]);

    $this->b = Project::factory()->fixedPrice()->create(['fixed_price_amount' => '600.00', 'budget_minutes' => 50, 'name' => 'Precio cerrado B']);
    $root = Task::factory()->create(['project_id' => $this->b->id, 'estimated_minutes' => 120]);
    $sub = Task::factory()->subtaskOf($root)->create(['estimated_minutes' => 60]);
    $this->entryB = TimeEntry::factory()->forTask($sub)->on('2026-09-23')->minutes(30)->create(['user_id' => $ana->id]);

    $this->scope = new ReportScope($this->admin, ReportFilters::fromQuery(['periodo' => 'mes', 'fecha' => '2026-09-01']));
});

it('la base suma la estimación efectiva de las tareas raíz, también la de un padre con subtareas sin estimar', function () {
    $bases = app(RevenueCalculator::class)->fixedPriceBases(new Collection([$this->a, $this->b]));

    expect($bases)->toBe([$this->a->id => 900, $this->b->id => 60]);
});

it('el ingreso estimado y la valoración de cada entrada usan esa base', function () {
    $revenue = app(RevenueCalculator::class)->compute($this->scope->entries(), Dimension::Project);
    $valuation = EntryValuation::for($this->scope->entries());

    expect($revenue[(string) $this->a->id]['income'])->toBe('600.00')
        ->and($revenue[(string) $this->b->id]['income'])->toBe('300.00')
        ->and($valuation->value($this->entryA->fresh()))->toBe(['rate' => null, 'income' => '600.000000', 'basis' => EntryValuation::FIXED_PRICE])
        ->and($valuation->value($this->entryB->fresh()))->toBe(['rate' => null, 'income' => '300.000000', 'basis' => EntryValuation::FIXED_PRICE]);
});
