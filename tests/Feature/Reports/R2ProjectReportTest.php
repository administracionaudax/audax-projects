<?php

use App\Enums\TimeEntryStatus;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use Inertia\Testing\AssertableInertia as Assert;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\Feature\Reports\R2Scenario;

/*
| Informe de proyecto (SPEC §10.3, D-044; R2) frente al escenario calculado a mano (R2Scenario):
| permisos (viewAllTime), KPIs, estimado frente a real con la regla de subtareas (SPEC §6), horas
| por persona, tipo y semana, estado de las tareas, hitos, datos económicos y exportación.
*/

beforeEach(function () {
    $this->s = R2Scenario::build($this);
    $this->url = fn (array $extra = []): string => "/informes/proyectos/{$this->s->web->id}?".R2Scenario::week($extra);
    $this->xlsx = function (string $content): array {
        $path = tempnam(sys_get_temp_dir(), 'r2').'.xlsx';
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

test('matriz de permisos (D-044): admin, responsables y gestores del proyecto; nadie más', function () {
    $s = $this->s;

    $this->actingAs($s->admin)->get(($this->url)())->assertOk();
    $this->actingAs($s->raul)->get(($this->url)())->assertOk();
    $this->actingAs($s->gema)->get(($this->url)())->assertOk();

    // Olga gestiona otro proyecto del mismo cliente; Ana y Luis son miembros sin gestión.
    $this->actingAs($s->olga)->get(($this->url)())->assertForbidden();
    $this->actingAs($s->ana)->get(($this->url)())->assertForbidden();
    $this->actingAs($s->luis)->get(($this->url)())->assertForbidden();
    $this->actingAs(userWithRole('client'))->get(($this->url)())->assertRedirect(route('portal.home'));

    auth()->logout();
    $this->get(($this->url)())->assertRedirect(route('login'));
});

test('KPIs de la semana con ingreso y rentabilidad, y la precisión de estimación de lo completado', function () {
    $s = $this->s;

    $this->actingAs($s->admin)->get(($this->url)())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/project')
            ->where('project.code', 'NAN-WEB')
            ->where('project.billing_type', 'hour_bank')
            ->where('project.client.name', 'Bodega Ñandú')
            ->where('summary.logged_minutes', 790)
            ->where('summary.billable_minutes', 790)
            ->where('summary.overage_minutes', 190)
            ->where('summary.in_bank_minutes', 600)
            ->where('summary.income', '1221.67')
            ->where('summary.cost', '330.00')
            ->where('summary.margin', '891.67')
            ->where('summary.margin_pct', 0.7299)
            // T2: estimada 240, real 400 (completada el 24/09).
            ->where('summary.estimation.tasks', 1)
            ->where('summary.estimation.estimated_minutes', 240)
            ->where('summary.estimation.actual_minutes', 400)
            ->where('summary.estimation.accuracy', 0.6)
            ->where('summary.estimation.deviation', 0.6667)
            ->missing('filters.query.proyecto')
            ->where('scope.team_only', false));
});

test('estimado frente a real de toda la vida del proyecto, con la regla de subtareas (SPEC §6)', function () {
    $s = $this->s;

    $this->actingAs($s->admin)->get(($this->url)())
        ->assertInertia(fn (Assert $page) => $page
            ->has('estimates.tasks', 5)
            // T1: su estimación (500) no cuenta; es la de S1 (200). Reales: 90 propios + 300 de S1.
            ->where('estimates.tasks.0.title', 'Diseño de la home')
            ->where('estimates.tasks.0.depth', 0)
            ->where('estimates.tasks.0.derived', true)
            ->where('estimates.tasks.0.estimated_minutes', 200)
            ->where('estimates.tasks.0.actual_minutes', 390)
            ->where('estimates.tasks.0.type.name', 'Diseño UI')
            ->where('estimates.tasks.1.title', 'Versión móvil')
            ->where('estimates.tasks.1.depth', 1)
            ->where('estimates.tasks.1.parent_id', $s->t1->id)
            ->where('estimates.tasks.1.estimated_minutes', 200)
            ->where('estimates.tasks.1.actual_minutes', 300)
            ->where('estimates.tasks.2.title', 'Versión escritorio')
            ->where('estimates.tasks.2.estimated_minutes', null)
            ->where('estimates.tasks.2.actual_minutes', 0)
            ->where('estimates.tasks.3.title', 'Maquetación')
            ->where('estimates.tasks.3.estimated_minutes', 240)
            ->where('estimates.tasks.3.actual_minutes', 400)
            ->where('estimates.tasks.3.completed', true)
            // Sin estimación, al final: las horas de agosto también cuentan (toda la vida).
            ->where('estimates.tasks.4.title', 'Diseño 2025')
            ->where('estimates.tasks.4.actual_minutes', 300)
            ->where('estimates.totals', [
                'estimated_minutes' => 440, 'actual_minutes' => 1090, 'other_minutes' => 0,
                'tasks' => 3, 'estimated_tasks' => 2, 'over_tasks' => 2,
            ])
            ->has('estimates.by_type', 3)
            ->where('estimates.by_type.0.type.name', 'Maquetación')
            ->where('estimates.by_type.0.estimated_minutes', 240)
            ->where('estimates.by_type.0.actual_minutes', 400)
            ->where('estimates.by_type.1.type.name', 'Diseño UI')
            ->where('estimates.by_type.1.estimated_minutes', 200)
            ->where('estimates.by_type.1.actual_minutes', 390)
            ->where('estimates.by_type.2.type', null)
            ->where('estimates.by_type.2.actual_minutes', 300));
});

test('horas por persona, por tipo de tarea y por semana del periodo', function () {
    $s = $this->s;

    $this->actingAs($s->admin)->get(($this->url)())
        ->assertInertia(fn (Assert $page) => $page
            ->has('byPerson', 2)
            ->where('byPerson.0.name', 'Luis')
            ->where('byPerson.0.logged_minutes', 400)
            ->where('byPerson.0.overage_minutes', 0)
            ->where('byPerson.1.name', 'Ana')
            ->where('byPerson.1.logged_minutes', 390)
            ->where('byPerson.1.overage_minutes', 190)
            ->has('byType', 2)
            ->where('byType.0.name', 'Maquetación')
            ->where('byType.0.logged_minutes', 400)
            ->where('byType.1.name', 'Diseño UI')
            ->where('byType.1.logged_minutes', 390)
            ->where('weekly', [[
                'week' => '2026-09-21', 'logged_minutes' => 790, 'billable_minutes' => 790,
                'in_bank_minutes' => 600, 'overage_minutes' => 190, 'income' => '1221.67',
            ]]));

    $this->actingAs($s->admin)->get("/informes/proyectos/{$s->web->id}?periodo=mes&fecha=2026-09-01")
        ->assertInertia(fn (Assert $page) => $page
            ->has('weekly', 5)
            ->where('weekly.0.week', '2026-08-31')
            ->where('weekly.0.logged_minutes', 0)
            ->where('weekly.0.income', null)
            ->where('weekly.3.week', '2026-09-21')
            ->where('weekly.3.logged_minutes', 790));
});

test('estado actual de las tareas (sin hitos) y vencidas, e hitos por fecha', function () {
    $s = $this->s;

    $this->actingAs($s->admin)->get(($this->url)())
        ->assertInertia(fn (Assert $page) => $page
            ->where('tasks.total', 5)
            ->where('tasks.by_category', ['todo' => 4, 'in_progress' => 0, 'done' => 1])
            // T1 vence el 24/09 y está abierta.
            ->where('tasks.overdue', 1)
            ->where('tasks.by_status.0.name', 'Por hacer')
            ->where('tasks.by_status.0.count', 4)
            ->has('milestones', 3)
            ->where('milestones.0.title', 'Arranque')
            ->where('milestones.0.due_date', '2026-09-01')
            ->where('milestones.0.completed', true)
            ->where('milestones.0.overdue', false)
            ->where('milestones.1.title', 'Entrega de diseño')
            ->where('milestones.1.overdue', true)
            ->where('milestones.2.title', 'Lanzamiento')
            ->where('milestones.2.overdue', false));
});

test('un responsable que no gestiona el proyecto ve las horas de su equipo, sin datos económicos', function () {
    $s = $this->s;

    $this->actingAs($s->raul)->get(($this->url)())
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.logged_minutes', 790)
            ->where('summary.income', null)
            ->where('summary.margin', null)
            ->where('byPerson.0.income', null)
            ->where('byPerson.0.cost', null)
            ->where('weekly.0.income', null)
            ->where('scope.team_only', true));

    // Con una persona de otro departamento en el proyecto, esa no la ve.
    $s->web->addMember($s->marta);
    TimeEntry::factory()->forTask($s->t2)->on('2026-09-24')->minutes(60)->create(['user_id' => $s->marta->id]);

    $this->actingAs($s->raul)->get(($this->url)())
        ->assertInertia(fn (Assert $page) => $page->where('summary.logged_minutes', 790));
    $this->actingAs($s->gema)->get(($this->url)())
        ->assertInertia(fn (Assert $page) => $page->where('summary.logged_minutes', 850)->where('scope.team_only', false));
});

test('los filtros de tipo y persona acotan las horas; el de tipo, también las tareas', function () {
    $s = $this->s;

    $this->actingAs($s->admin)->get(($this->url)(['tipo' => [$s->layoutType->id]]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.logged_minutes', 400)
            ->where('tasks.total', 2)
            ->where('estimates.totals.actual_minutes', 400)
            ->where('estimates.tasks.0.title', 'Maquetación'));

    $this->actingAs($s->admin)->get(($this->url)(['persona' => [$s->ana->id]]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.logged_minutes', 390)
            ->has('byPerson', 1)
            ->where('estimates.totals.actual_minutes', 690));
});

test('exporta el estimado frente a real y las horas por persona (con importes solo con permiso)', function () {
    $s = $this->s;

    $tasks = ($this->xlsx)($this->actingAs($s->admin)->get(($this->url)(['formato' => 'xlsx']))->assertOk()->streamedContent());
    expect($tasks[0])->toBe(['Tarea', 'Tarea principal', 'Tipo', 'Estado', 'Horas estimadas', 'Horas reales', 'Desviación (horas)', 'Desviación (%)'])
        ->and($tasks[1])->toBe(['Diseño de la home', '', 'Diseño UI', 'Por hacer', 3.33, 6.5, 3.17, 95])
        ->and($tasks[2])->toBe(['Versión móvil', 'Diseño de la home', 'Diseño UI', 'Por hacer', 3.33, 5, 1.67, 50])
        ->and($tasks[3][4])->toBe('')
        ->and($tasks[4])->toBe(['Maquetación', '', 'Maquetación', 'Hecha', 4, 6.67, 2.67, 66.7])
        ->and($tasks[5][0])->toBe('Diseño 2025')
        ->and($tasks[6])->toBe(['Total', '', '', '', 7.33, 18.17, '', '']);

    $people = ($this->xlsx)($this->actingAs($s->admin)->get(($this->url)(['formato' => 'xlsx', 'tabla' => 'personas']))->streamedContent());
    expect($people[0])->toBe(['Persona', 'Horas imputadas', 'Horas facturables', 'Horas dentro de bolsa', 'Horas en exceso', 'Ingreso estimado (€)', 'Coste (€)'])
        ->and($people[1][0])->toBe('Luis')
        ->and($people[1][6])->toBe(200);

    $peopleNoMoney = ($this->xlsx)($this->actingAs($s->raul)->get(($this->url)(['formato' => 'xlsx', 'tabla' => 'personas']))->streamedContent());
    expect($peopleNoMoney[0])->toHaveCount(5);

    $weeks = ($this->xlsx)($this->actingAs($s->admin)->get(($this->url)(['formato' => 'xlsx', 'tabla' => 'semanas']))->streamedContent());
    expect($weeks[1])->toBe(['2026-09-21', 13.17, 13.17, 10, 3.17, 1221.67]);
});

test('la precisión de estimación: el gestor cuenta las tareas de cualquiera; un responsable, las de su equipo', function () {
    $s = $this->s;

    // T5 de Marta (Desarrollo): estimada 60, real 90, completada el 25/09.
    $s->web->addMember($s->marta);
    $t5 = Task::factory()->inBank($s->b1)->assignedTo($s->marta)->create(['title' => 'Revisión técnica', 'estimated_minutes' => 60]);
    TimeEntry::factory()->forTask($t5)->on('2026-09-24')->minutes(90)->status(TimeEntryStatus::Approved)->create(['user_id' => $s->marta->id]);
    $t5->update(['status_id' => TaskStatus::query()->where('category', 'done')->value('id')]);

    // Gema (gestora) y el admin: T2 (240 → 400) y T5 (60 → 90) = 300 frente a 490.
    foreach ([$s->gema, $s->admin] as $viewer) {
        $this->actingAs($viewer)->get(($this->url)())
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.estimation.tasks', 2)
                ->where('summary.estimation.estimated_minutes', 300)
                ->where('summary.estimation.actual_minutes', 490)
                ->where('summary.estimation.accuracy', 0.6122));
    }

    // Raúl (responsable de Diseño, no gestiona el proyecto): solo T2, de Luis.
    $this->actingAs($s->raul)->get(($this->url)())
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.estimation.tasks', 1)
            ->where('summary.estimation.estimated_minutes', 240));

    // Con el filtro de persona, la gestora ve solo las de esa persona.
    $this->actingAs($s->gema)->get(($this->url)(['persona' => [$s->marta->id]]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.estimation.tasks', 1)
            ->where('summary.estimation.estimated_minutes', 60)
            ->where('summary.estimation.actual_minutes', 90));
});

test('los títulos de tarea que empiezan por = no se exportan como fórmula', function () {
    $s = $this->s;
    $s->t2->forceFill(['title' => '=cmd|\'/C calc\'!A0'])->save();

    $content = $this->actingAs($s->admin)->get(($this->url)(['formato' => 'xlsx']))->assertOk()->streamedContent();
    $path = tempnam(sys_get_temp_dir(), 'r2').'.xlsx';
    file_put_contents($path, $content);
    $zip = new ZipArchive;
    $zip->open($path);
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    unlink($path);

    expect($sheet)->not->toContain('<f>')
        ->and(($this->xlsx)($content)[4][0])->toBe('=cmd|\'/C calc\'!A0');

    $csv = $this->actingAs($s->admin)->get(($this->url)(['formato' => 'csv']))->streamedContent();
    expect($csv)->toContain('"\'=cmd|\'/C calc\'!A0"');
});

test('la exportación de un proyecto sin bolsas no lleva «Horas dentro de bolsa», como la página', function () {
    $s = $this->s;
    $url = fn (array $extra): string => "/informes/proyectos/{$s->campaign->id}?".R2Scenario::week($extra);

    // NAN-CAMP (por horas): Marta 120 facturables y Ana 30 no facturables.
    $people = ($this->xlsx)($this->actingAs($s->admin)->get($url(['formato' => 'xlsx', 'tabla' => 'personas']))->assertOk()->streamedContent());
    expect($people[0])->toBe(['Persona', 'Horas imputadas', 'Horas facturables', 'Horas en exceso', 'Ingreso estimado (€)', 'Coste (€)'])
        ->and($people[1])->toBe(['Marta', 2, 2, 0, 116, 50]);

    foreach (['tipos', 'semanas'] as $table) {
        $rows = ($this->xlsx)($this->actingAs($s->admin)->get($url(['formato' => 'xlsx', 'tabla' => $table]))->streamedContent());
        expect($rows[0])->not->toContain('Horas dentro de bolsa');
    }

    // En uno de bolsas, sí.
    $weeks = ($this->xlsx)($this->actingAs($s->admin)->get(($this->url)(['formato' => 'xlsx', 'tabla' => 'semanas']))->streamedContent());
    expect($weeks[0])->toContain('Horas dentro de bolsa');
});
