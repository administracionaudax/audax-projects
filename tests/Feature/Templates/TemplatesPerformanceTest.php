<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\RecurringTaskRule;
use App\Models\Task;
use App\Models\TaskType;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Rendimiento de las pantallas de plantillas y tareas recurrentes con los datos de ejemplo
| (DemoDataSeeder), como tests/Feature/Performance/Phase1PagesPerformanceTest.php:
|   1. cada página cabe en su presupuesto de consultas (con la caché ya caliente),
|   2. ninguna consulta se repite más de 4 veces (el síntoma de un N+1),
|   3. el número de consultas NO crece al añadir más plantillas, reglas, tareas creadas por ellas,
|      responsables y bolsas.
| Las secciones de Ajustes van en una prop diferida: se miden como la recarga parcial que hace
| Inertia tras pintar la página, y la página de Ajustes no suma consultas.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);

    $this->admin = User::query()->where('email', 'admin@example.com')->sole();
    $this->raul = User::query()->where('email', 'responsable@example.com')->sole();
    $this->project = Project::query()->where('code', 'ARR-WEB')->sole();
    $this->bank = HourBank::query()->where('project_id', $this->project->id)->open()->orderBy('id')->firstOrFail();

    // Presupuesto: lo medido con los datos de ejemplo más 3 (incluye las props compartidas).
    $this->budgets = [
        'templates.index' => 8,
        'templates.edit' => 7,
        'templates.create.duplicate' => 7,
        'recurring.index' => 11,
        'projects.create' => 12,
        // +1 fija con la Fase 6: el total sin leer del chat (prop compartida `chat.unread`).
        'projects.settings' => 15,
        'projects.settings.planning' => 24,
    ];

    $this->pages = function (): array {
        $template = ProjectTemplate::query()->orderBy('id')->firstOrFail();
        $partial = [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'projects/settings',
            'X-Inertia-Partial-Data' => 'templating,recurring',
        ];

        return [
            'templates.index' => [$this->admin, '/admin/plantillas', []],
            'templates.edit' => [$this->admin, "/admin/plantillas/{$template->id}/editar", []],
            'templates.create.duplicate' => [$this->admin, "/admin/plantillas/nueva?desde={$template->id}", []],
            'recurring.index' => [$this->admin, '/admin/tareas-recurrentes?estado=todas', []],
            'projects.create' => [$this->raul, '/proyectos/nuevo', []],
            'projects.settings' => [$this->raul, "/proyectos/{$this->project->id}/ajustes", []],
            'projects.settings.planning' => [$this->raul, "/proyectos/{$this->project->id}/ajustes", $partial],
        ];
    };

    $this->measure = function (): array {
        $results = [];

        foreach (($this->pages)() as $label => [$user, $url, $headers]) {
            $user->refresh();
            // La primera petición calienta las cachés de ajustes, permisos y estados.
            $this->actingAs($user)->get($url, $headers);

            $queries = [];
            DB::listen(function (QueryExecuted $query) use (&$queries): void {
                $queries[] = $query->sql;
            });
            $response = $this->actingAs($user)->get($url, $headers);
            app('events')->forget(QueryExecuted::class);

            $counts = array_count_values($queries);
            arsort($counts);
            $results[$label] = [
                'status' => $response->getStatusCode(),
                'total' => count($queries),
                'repeats' => $counts === [] ? 0 : (int) reset($counts),
                'repeated' => Str::limit((string) array_key_first($counts), 200),
            ];
        }

        return $results;
    };

    // Más plantillas, reglas (con responsable, bolsa y tipo), tareas creadas por ellas y personas.
    $this->grow = function (int $round): void {
        $type = TaskType::query()->active()->orderBy('id')->firstOrFail();
        $structure = [
            'tasks' => array_map(fn (int $i): array => [
                'ref' => "t{$i}", 'title' => "Tarea {$i}", 'task_type_id' => $type->id,
                'start_offset_days' => $i, 'duration_days' => 2, 'parent_ref' => $i % 3 === 0 ? 't1' : null,
            ], range(1, 12)),
            'dependencies' => [['from_ref' => 't1', 'to_ref' => 't2'], ['from_ref' => 't2', 'to_ref' => 't4']],
        ];

        foreach (range(1, 4) as $i) {
            ProjectTemplate::query()->create(['name' => "Plantilla {$round}.{$i}", 'structure' => $structure]);
            ProjectTemplate::query()->create(['name' => "Inactiva {$round}.{$i}", 'structure' => $structure, 'is_active' => false]);
        }

        foreach (range(1, 4) as $i) {
            $person = User::factory()->employee()->create();
            $this->project->addMember($person);
            $rule = RecurringTaskRule::query()->create([
                'project_id' => $this->project->id, 'hour_bank_id' => $this->bank->id, 'title' => "Recurrente {$round}.{$i}",
                'task_type_id' => $type->id, 'assignee_user_id' => $person->id, 'frequency' => $i % 2 === 0 ? 'weekly' : 'monthly',
                'weekday' => 1, 'month_day' => 28, 'starts_on' => now()->subYear()->toDateString(), 'created_by' => $this->raul->id,
            ]);
            Task::factory()->inBank($this->bank)->assignedTo($person)->create([
                'recurring_task_rule_id' => $rule->id, 'occurrence_date' => now()->subDays($round * 10 + $i)->toDateString(),
            ]);

            $other = Project::factory()->hourBank()->create();
            $otherBank = HourBank::factory()->create(['project_id' => $other->id]);
            RecurringTaskRule::query()->create([
                'project_id' => $other->id, 'hour_bank_id' => $otherBank->id, 'title' => "Otra {$round}.{$i}",
                'assignee_user_id' => $person->id, 'frequency' => 'weekly', 'weekday' => 3, 'starts_on' => now()->toDateString(),
            ]);
        }
    };
});

test('las pantallas de plantillas y recurrentes caben en su presupuesto de consultas y no crecen con los datos', function () {
    ($this->grow)(1);
    $before = ($this->measure)();

    ($this->grow)(2);
    ($this->grow)(3);
    $after = ($this->measure)();

    $problems = [];
    foreach ($after as $label => $result) {
        if ($result['status'] !== 200) {
            $problems[] = "{$label}: estado {$result['status']}";
        }
        if ($result['total'] > $this->budgets[$label]) {
            $problems[] = "{$label}: {$result['total']} consultas (presupuesto {$this->budgets[$label]})";
        }
        if ($result['repeats'] > 4) {
            $problems[] = "{$label}: la misma consulta {$result['repeats']} veces: {$result['repeated']}";
        }
        if ($result['total'] > $before[$label]['total']) {
            $problems[] = "{$label}: {$before[$label]['total']} → {$result['total']} consultas al crecer los datos";
        }
    }

    if (getenv('PERF_REPORT')) {
        fwrite(STDERR, "\n".implode("\n", array_map(fn (string $label, array $result): string => sprintf('%-30s %3d %3d q', $label, $result['status'], $result['total']), array_keys($after), $after))."\n");
    }

    expect($problems)->toBe([]);
});

test('la página de Ajustes no carga las secciones diferidas', function () {
    $this->actingAs($this->raul)
        ->get("/proyectos/{$this->project->id}/ajustes")
        ->assertInertia(fn ($page) => $page->missing('templating')->missing('recurring'));
});
