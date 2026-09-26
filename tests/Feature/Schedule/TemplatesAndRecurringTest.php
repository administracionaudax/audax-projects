<?php

use App\Domain\Recurring\RecurringTaskGenerator;
use App\Domain\Templates\ProjectTemplateService;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\RecurringTaskRule;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\TaskStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/*
| Plantillas de proyecto (D-055) y tareas recurrentes (D-056).
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();
    $this->admin = User::factory()->admin()->create();
    $this->templates = app(ProjectTemplateService::class);
    $this->structure = [
        'tasks' => [
            ['ref' => 'dis', 'title' => 'Diseño', 'start_offset_days' => 0, 'duration_days' => 5, 'estimated_minutes' => 900],
            ['ref' => 'dis-home', 'parent_ref' => 'dis', 'title' => 'Home', 'start_offset_days' => 0, 'duration_days' => 2, 'estimated_minutes' => 300],
            ['ref' => 'dev', 'title' => 'Desarrollo', 'start_offset_days' => 7, 'duration_days' => 10],
            ['ref' => 'go', 'title' => 'Publicación', 'start_offset_days' => 17, 'is_milestone' => true],
        ],
        'dependencies' => [['from_ref' => 'dis', 'to_ref' => 'dev'], ['from_ref' => 'dev', 'to_ref' => 'go']],
    ];
});

it('aplica una plantilla con fechas relativas, subtareas, hito y dependencias', function () {
    $template = ProjectTemplate::query()->create(['name' => 'Web', 'structure' => $this->structure]);
    $bank = HourBank::factory()->create();
    $project = $bank->project;

    $tasks = collect($this->templates->apply($template, $project, CarbonImmutable::parse('2026-10-05'), $this->admin, $bank))->keyBy('title');

    expect($tasks)->toHaveCount(4)
        ->and($tasks['Diseño']->start_date->toDateString())->toBe('2026-10-05')
        ->and($tasks['Diseño']->due_date->toDateString())->toBe('2026-10-09')
        ->and($tasks['Home']->parent_task_id)->toBe($tasks['Diseño']->id)
        ->and($tasks['Home']->hour_bank_id)->toBe($bank->id)
        ->and($tasks['Desarrollo']->start_date->toDateString())->toBe('2026-10-12')
        ->and($tasks['Desarrollo']->due_date->toDateString())->toBe('2026-10-21')
        ->and($tasks['Publicación']->is_milestone)->toBeTrue()
        ->and($tasks['Publicación']->start_date)->toBeNull()
        ->and($tasks['Publicación']->due_date->toDateString())->toBe('2026-10-22')
        ->and(TaskDependency::query()->count())->toBe(2);
});

it('captura un proyecto como plantilla y se puede volver a aplicar igual', function () {
    $template = ProjectTemplate::query()->create(['name' => 'Web', 'structure' => $this->structure]);
    $source = Project::factory()->create(['start_date' => '2026-10-05']);
    $this->templates->apply($template, $source, CarbonImmutable::parse('2026-10-05'), $this->admin);

    $captured = $this->templates->capture($source, 'Copia', null, $this->admin);
    $target = Project::factory()->create();
    $copies = collect($this->templates->apply($captured, $target, CarbonImmutable::parse('2026-11-02'), $this->admin))->keyBy('title');

    expect($captured->structure['tasks'])->toHaveCount(4)
        ->and($captured->structure['dependencies'])->toHaveCount(2)
        ->and($copies['Desarrollo']->start_date->toDateString())->toBe('2026-11-09')
        ->and($copies['Publicación']->due_date->toDateString())->toBe('2026-11-19')
        ->and(TaskDependency::query()->count())->toBe(4);
});

it('rechaza plantillas mal formadas', function (array $structure) {
    expect(fn () => ProjectTemplateService::normalize($structure))->toThrow(ValidationException::class);
})->with([
    'sin tareas' => [['tasks' => []]],
    'referencia repetida' => [['tasks' => [['ref' => 'a', 'title' => 'A'], ['ref' => 'a', 'title' => 'B']]]],
    'subtarea de subtarea' => [['tasks' => [['ref' => 'a', 'title' => 'A'], ['ref' => 'b', 'title' => 'B', 'parent_ref' => 'a'], ['ref' => 'c', 'title' => 'C', 'parent_ref' => 'b']]]],
    'dependencia rota' => [['tasks' => [['ref' => 'a', 'title' => 'A']], 'dependencies' => [['from_ref' => 'a', 'to_ref' => 'x']]]],
]);

it('una plantilla con un ciclo en sus dependencias no se aplica', function () {
    $template = ProjectTemplate::query()->create(['name' => 'Ciclo', 'structure' => [
        'tasks' => [['ref' => 'a', 'title' => 'A'], ['ref' => 'b', 'title' => 'B']],
        'dependencies' => [['from_ref' => 'a', 'to_ref' => 'b'], ['from_ref' => 'b', 'to_ref' => 'a']],
    ]]);

    expect(fn () => $this->templates->apply($template, Project::factory()->create(), CarbonImmutable::parse('2026-10-05'), $this->admin))
        ->toThrow(ValidationException::class)
        ->and(Task::query()->count())->toBe(0);
});

it('calcula las fechas de las reglas semanales y mensuales', function () {
    $weekly = new RecurringTaskRule(['frequency' => 'weekly', 'interval' => 2, 'weekday' => 1, 'starts_on' => '2026-10-01']);
    $monthly = new RecurringTaskRule(['frequency' => 'monthly', 'interval' => 1, 'month_day' => 31, 'starts_on' => '2026-01-15', 'ends_on' => '2026-04-30']);

    expect($weekly->occurrencesBetween(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-11-10')))
        ->toBe(['2026-10-12', '2026-10-26', '2026-11-09'])
        ->and($monthly->occurrencesBetween(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31')))
        ->toBe(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30']);
});

it('genera las instancias pendientes una sola vez, con su responsable, bolsa y vencimiento', function () {
    $bank = HourBank::factory()->create();
    $employee = User::factory()->employee()->create();
    $bank->project->addMember($employee);
    $rule = RecurringTaskRule::query()->create([
        'project_id' => $bank->project_id, 'hour_bank_id' => $bank->id, 'title' => 'Informe semanal',
        'assignee_user_id' => $employee->id, 'estimated_minutes' => 60, 'frequency' => 'weekly', 'weekday' => 1,
        'due_offset_days' => 4, 'starts_on' => '2026-09-28', 'created_by' => $this->admin->id,
    ]);
    $generator = app(RecurringTaskGenerator::class);

    expect($generator->generate(CarbonImmutable::parse('2026-10-06')))->toBe(2);
    expect($generator->generate(CarbonImmutable::parse('2026-10-06')))->toBe(0);

    $tasks = Task::query()->where('recurring_task_rule_id', $rule->id)->orderBy('occurrence_date')->get();
    expect($tasks->pluck('occurrence_date')->map->toDateString()->all())->toBe(['2026-09-28', '2026-10-05'])
        ->and($tasks[1]->due_date->toDateString())->toBe('2026-10-09')
        ->and($tasks[1]->assignee_user_id)->toBe($employee->id)
        ->and($tasks[1]->hour_bank_id)->toBe($bank->id)
        ->and($rule->fresh()->last_generated_on->toDateString())->toBe('2026-10-06');
});

it('se salta las reglas de proyectos archivados y sigue si una instancia no se puede crear', function () {
    $archived = Project::factory()->archived()->create();
    RecurringTaskRule::query()->create(['project_id' => $archived->id, 'title' => 'X', 'frequency' => 'weekly', 'weekday' => 1, 'starts_on' => '2026-09-28', 'created_by' => $this->admin->id]);
    $closed = HourBank::factory()->closed()->create();
    RecurringTaskRule::query()->create(['project_id' => $closed->project_id, 'hour_bank_id' => $closed->id, 'title' => 'Y', 'frequency' => 'weekly', 'weekday' => 1, 'starts_on' => '2026-09-28', 'created_by' => $this->admin->id]);

    expect(app(RecurringTaskGenerator::class)->generate(CarbonImmutable::parse('2026-10-06')))->toBe(0)
        ->and(Task::query()->count())->toBe(0);
});

it('el comando programado existe', function () {
    $this->artisan('tasks:generate-recurring')->assertSuccessful();
});
