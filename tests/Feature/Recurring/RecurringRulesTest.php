<?php

use App\Domain\Recurring\RecurrenceDescriber;
use App\Domain\Recurring\RecurringTaskGenerator;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\RecurringTaskRule;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TaskType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Tareas recurrentes de un proyecto (D-059), desde sus Ajustes: validación de la regla, frase
| legible y próxima fecha, generación inmediata de la tarea de hoy (idempotente y sin recuperar
| fechas pasadas), responsables de baja, bolsas cerradas, proyectos archivados y borrado.
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();
    // Lunes 5 de octubre de 2026, por la mañana en Madrid.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'Europe/Madrid'));

    $this->owner = User::factory()->employee()->create();
    $this->member = User::factory()->employee()->create(['name' => 'Elena Ruiz']);
    $this->project = Project::factory()->create(['owner_user_id' => $this->owner->id]);
    $this->project->addMember($this->member);
    $this->type = TaskType::factory()->create(['name' => 'Gestión']);
    $this->url = "/proyectos/{$this->project->id}/tareas-recurrentes";
    $this->data = fn (array $overrides = []): array => [
        'title' => 'Informe semanal',
        'description' => 'Resumen para el cliente',
        'task_type_id' => $this->type->id,
        'assignee_user_id' => $this->member->id,
        'hour_bank_id' => null,
        'estimated_minutes' => 60,
        'priority' => 'high',
        'frequency' => 'weekly',
        'interval' => 1,
        'weekday' => 1,
        'month_day' => null,
        'due_offset_days' => 2,
        'starts_on' => '2026-10-05',
        'ends_on' => null,
        'is_active' => true,
        ...$overrides,
    ];
    $this->generator = app(RecurringTaskGenerator::class);
    $this->describer = app(RecurrenceDescriber::class);
    $this->actingAs($this->owner);
});

it('crea la regla y, como hoy toca, ya crea la tarea de hoy', function () {
    $this->from("/proyectos/{$this->project->id}/ajustes")
        ->post($this->url, ($this->data)())
        ->assertSessionHasNoErrors()
        ->assertRedirect("/proyectos/{$this->project->id}/ajustes")
        ->assertInertiaFlash('toast.message', 'Tarea recurrente «Informe semanal» creada. Hoy toca: ya está creada la tarea de hoy.');

    $rule = RecurringTaskRule::query()->sole();
    $task = Task::query()->where('recurring_task_rule_id', $rule->id)->sole();

    expect($rule->created_by)->toBe($this->owner->id)
        ->and($rule->last_generated_on->toDateString())->toBe('2026-10-05')
        ->and($task->occurrence_date->toDateString())->toBe('2026-10-05')
        ->and($task->start_date->toDateString())->toBe('2026-10-05')
        ->and($task->due_date->toDateString())->toBe('2026-10-07')
        ->and($task->assignee_user_id)->toBe($this->member->id)
        ->and($task->task_type_id)->toBe($this->type->id)
        ->and($task->estimated_minutes)->toBe(60)
        ->and($task->priority->value)->toBe('high')
        ->and($task->project_id)->toBe($this->project->id);
});

it('si hoy no toca, no crea nada y enseña la frase y la próxima fecha', function () {
    $this->post($this->url, ($this->data)(['weekday' => 3]))
        ->assertInertiaFlash('toast.message', 'Tarea recurrente «Informe semanal» creada.');

    expect(Task::query()->count())->toBe(0);

    $this->get("/proyectos/{$this->project->id}/ajustes")->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps('planning', fn (Assert $reload) => $reload
            ->has('recurring.rules', 1)
            ->where('recurring.rules.0.summary', 'Cada semana, los miércoles')
            ->where('recurring.rules.0.next_date', '2026-10-07')
            ->where('recurring.rules.0.assignee.name', 'Elena Ruiz')
            ->where('recurring.rules.0.warnings', [])
            ->where('recurring.today', '2026-10-05')
            ->where('recurring.archived', false)));
});

it('la generación inmediata es idempotente: editar, desactivar y reactivar el mismo día no duplica', function () {
    $this->post($this->url, ($this->data)());
    $rule = RecurringTaskRule::query()->sole();

    $this->put("{$this->url}/{$rule->id}", ($this->data)(['title' => 'Informe de la semana']))->assertSessionHasNoErrors();
    $this->put("{$this->url}/{$rule->id}/estado", ['is_active' => false])
        ->assertInertiaFlash('toast.message', 'Tarea recurrente «Informe de la semana» desactivada: no se crearán más tareas.');
    $this->put("{$this->url}/{$rule->id}/estado", ['is_active' => true])->assertSessionHasNoErrors();
    $this->generator->generate(CarbonImmutable::parse('2026-10-05'));

    expect(Task::query()->where('recurring_task_rule_id', $rule->id)->count())->toBe(1)
        ->and($rule->fresh()->title)->toBe('Informe de la semana');

    // Aunque la de hoy esté en la papelera, no se vuelve a crear.
    Task::query()->where('recurring_task_rule_id', $rule->id)->sole()->delete();
    $this->put("{$this->url}/{$rule->id}", ($this->data)())->assertSessionHasNoErrors();
    expect(Task::withTrashed()->where('recurring_task_rule_id', $rule->id)->count())->toBe(1);
});

it('una regla que empieza en el pasado, o que se reactiva, no crea tareas atrasadas', function () {
    $this->post($this->url, ($this->data)(['starts_on' => '2026-08-03', 'weekday' => 1]));
    $rule = RecurringTaskRule::query()->sole();

    expect(Task::query()->where('recurring_task_rule_id', $rule->id)->pluck('occurrence_date')->map->toDateString()->all())->toBe(['2026-10-05']);

    // Parada tres semanas: al reactivarla, empieza a contar desde ese día.
    $this->put("{$this->url}/{$rule->id}/estado", ['is_active' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-10-28 09:00:00', 'Europe/Madrid'));
    $this->put("{$this->url}/{$rule->id}/estado", ['is_active' => true])->assertSessionHasNoErrors();
    $this->generator->generate(CarbonImmutable::parse('2026-10-28'));

    expect(Task::query()->where('recurring_task_rule_id', $rule->id)->count())->toBe(1)
        ->and($rule->fresh()->last_generated_on->toDateString())->toBe('2026-10-28');

    // El comando diario sigue desde ahí.
    expect($this->generator->generate(CarbonImmutable::parse('2026-11-02')))->toBe(1);
});

it('valida la regla con errores comprensibles', function (array $overrides, string $field) {
    $this->post($this->url, ($this->data)($overrides))->assertSessionHasErrors($field);

    expect(RecurringTaskRule::query()->count())->toBe(0);
})->with([
    'sin título' => [['title' => ''], 'title'],
    'frecuencia desconocida' => [['frequency' => 'daily'], 'frequency'],
    'día de la semana 0' => [['weekday' => 0], 'weekday'],
    'día de la semana 8' => [['weekday' => 8], 'weekday'],
    'semanal sin día' => [['weekday' => null], 'weekday'],
    'día del mes 32' => [['frequency' => 'monthly', 'month_day' => 32], 'month_day'],
    'mensual sin día' => [['frequency' => 'monthly', 'month_day' => null], 'month_day'],
    'intervalo 0' => [['interval' => 0], 'interval'],
    'intervalo 13' => [['interval' => 13], 'interval'],
    'vencimiento de 61 días' => [['due_offset_days' => 61], 'due_offset_days'],
    'hasta antes de desde' => [['ends_on' => '2026-10-04'], 'ends_on'],
    'prioridad desconocida' => [['priority' => 'máxima'], 'priority'],
    'estimación de 0' => [['estimated_minutes' => 0], 'estimated_minutes'],
]);

it('el responsable es un miembro activo del proyecto y el tipo, uno activo', function () {
    $outsider = User::factory()->employee()->create();
    $inactive = User::factory()->employee()->inactive()->create();
    $this->project->addMember($inactive);
    $client = User::factory()->client()->create();
    $this->project->addMember($client);
    $inactiveType = TaskType::factory()->create(['is_active' => false]);
    $message = 'Elige a un miembro activo del proyecto.';

    $this->post($this->url, ($this->data)(['assignee_user_id' => $outsider->id]))->assertSessionHasErrors(['assignee_user_id' => $message]);
    $this->post($this->url, ($this->data)(['assignee_user_id' => $inactive->id]))->assertSessionHasErrors(['assignee_user_id' => $message]);
    $this->post($this->url, ($this->data)(['assignee_user_id' => $client->id]))->assertSessionHasErrors(['assignee_user_id' => $message]);
    $this->post($this->url, ($this->data)(['task_type_id' => $inactiveType->id]))->assertSessionHasErrors('task_type_id');

    // Sin responsable también vale.
    $this->post($this->url, ($this->data)(['assignee_user_id' => null]))->assertSessionHasNoErrors();
    expect(Task::query()->sole()->assignee_user_id)->toBeNull();
});

it('en un proyecto de bolsas la bolsa es obligatoria, del proyecto y abierta', function () {
    $project = Project::factory()->hourBank()->create(['owner_user_id' => $this->owner->id]);
    $project->addMember($this->member);
    $open = HourBank::factory()->create(['project_id' => $project->id]);
    $closed = HourBank::factory()->closed()->create(['project_id' => $project->id]);
    $other = HourBank::factory()->create();
    $url = "/proyectos/{$project->id}/tareas-recurrentes";

    $this->post($url, ($this->data)())->assertSessionHasErrors(['hour_bank_id' => 'En un proyecto de bolsas, elige la bolsa de las tareas.']);
    $this->post($url, ($this->data)(['hour_bank_id' => $closed->id]))->assertSessionHasErrors(['hour_bank_id' => 'Elige una bolsa abierta de este proyecto.']);
    $this->post($url, ($this->data)(['hour_bank_id' => $other->id]))->assertSessionHasErrors('hour_bank_id');

    $this->post($url, ($this->data)(['hour_bank_id' => $open->id]))->assertSessionHasNoErrors();
    expect(Task::query()->sole()->hour_bank_id)->toBe($open->id);

    // En un proyecto sin bolsas, la bolsa se ignora.
    $this->post($this->url, ($this->data)(['hour_bank_id' => $open->id, 'weekday' => 2]))->assertSessionHasNoErrors();
    expect(RecurringTaskRule::query()->where('project_id', $this->project->id)->sole()->hour_bank_id)->toBeNull();
});

it('si su bolsa se cierra, no se reactiva y avisa en la lista', function () {
    $project = Project::factory()->hourBank()->create(['owner_user_id' => $this->owner->id]);
    $bank = HourBank::factory()->create(['project_id' => $project->id, 'name' => 'Bolsa T4']);
    $url = "/proyectos/{$project->id}/tareas-recurrentes";
    $this->post($url, ($this->data)(['hour_bank_id' => $bank->id, 'assignee_user_id' => null, 'weekday' => 3]));
    $rule = RecurringTaskRule::query()->sole();

    $bank->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

    $this->get("/proyectos/{$project->id}/ajustes")->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps('planning', fn (Assert $reload) => $reload
            ->where('recurring.rules.0.hour_bank.open', false)
            ->where('recurring.rules.0.warnings', ['La bolsa «Bolsa T4» ya no admite tareas: no se crearán hasta que elijas otra.'])
            ->where('recurring.options.banks', [])));

    $this->put("{$url}/{$rule->id}/estado", ['is_active' => false]);
    $this->put("{$url}/{$rule->id}/estado", ['is_active' => true])
        ->assertSessionHasErrors(['is_active' => 'La bolsa «Bolsa T4» ya no admite tareas: edita la regla y elige una bolsa abierta.']);

    expect($rule->fresh()->is_active)->toBeFalse();
});

it('un proyecto archivado no tiene reglas activas ni crea tareas', function () {
    $this->post($this->url, ($this->data)(['weekday' => 3]));
    $rule = RecurringTaskRule::query()->sole();
    $this->put("{$this->url}/{$rule->id}/estado", ['is_active' => false]);
    $this->project->forceFill(['status' => 'archived'])->save();

    $this->post($this->url, ($this->data)())->assertSessionHasErrors(['is_active' => 'El proyecto está archivado: no puede tener tareas recurrentes activas.']);
    $this->put("{$this->url}/{$rule->id}/estado", ['is_active' => true])->assertSessionHasErrors('is_active');

    // Una regla activa que se queda en un proyecto archivado no genera y lo avisa.
    $rule = $rule->fresh();
    $rule->forceFill(['is_active' => true, 'weekday' => 1, 'last_generated_on' => null])->save();
    expect($this->generator->generateFor($rule, CarbonImmutable::parse('2026-10-05')))->toBeNull()
        ->and(Task::query()->count())->toBe(0);

    $this->get("/proyectos/{$this->project->id}/ajustes")->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps('planning', fn (Assert $reload) => $reload
            ->where('recurring.archived', true)
            ->where('recurring.rules.0.next_date', null)
            ->where('recurring.rules.0.warnings', ['El proyecto está archivado: no se crean tareas.'])));
});

it('si el responsable está de baja, la tarea se crea sin responsable (y sin tipo si se desactivó)', function () {
    $this->post($this->url, ($this->data)(['weekday' => 3]));
    $rule = RecurringTaskRule::query()->sole();
    $this->member->forceFill(['is_active' => false])->save();
    $this->type->forceFill(['is_active' => false])->save();

    expect($this->generator->generate(CarbonImmutable::parse('2026-10-07')))->toBe(1);

    $task = Task::query()->where('recurring_task_rule_id', $rule->id)->sole();
    expect($task->assignee_user_id)->toBeNull()
        ->and($task->task_type_id)->toBeNull()
        ->and($task->occurrence_date->toDateString())->toBe('2026-10-07');

    $this->get("/proyectos/{$this->project->id}/ajustes")->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps('planning', fn (Assert $reload) => $reload
            ->where('recurring.rules.0.warnings', ['Elena Ruiz está de baja: las tareas se crearán sin responsable.'])
            ->where('recurring.options.members', [['id' => $this->owner->id, 'name' => $this->owner->name]])
            ->has('recurring.recent', 1)
            ->where('recurring.recent.0.id', $task->id)
            ->where('recurring.recent.0.occurrence_date', '2026-10-07')));
});

it('borrar una regla conserva las tareas que ya creó', function () {
    $this->post($this->url, ($this->data)());
    $rule = RecurringTaskRule::query()->sole();

    $this->delete("{$this->url}/{$rule->id}")
        ->assertRedirect("/proyectos/{$this->project->id}/ajustes")
        ->assertInertiaFlash('toast.message', 'Tarea recurrente «Informe semanal» eliminada. Las tareas ya creadas se conservan.');

    expect(RecurringTaskRule::query()->count())->toBe(0)
        ->and(Task::query()->sole()->recurring_task_rule_id)->toBeNull();
});

it('una regla de otro proyecto no se encuentra por la URL de este', function () {
    $other = Project::factory()->create(['owner_user_id' => $this->owner->id]);
    $rule = RecurringTaskRule::query()->create(['project_id' => $other->id, 'title' => 'Otra', 'frequency' => 'weekly', 'weekday' => 1, 'starts_on' => '2026-10-05']);

    $this->put("{$this->url}/{$rule->id}", ($this->data)())->assertNotFound();
    $this->put("{$this->url}/{$rule->id}/estado", ['is_active' => false])->assertNotFound();
    $this->delete("{$this->url}/{$rule->id}")->assertNotFound();

    expect($rule->fresh()->is_active)->toBeTrue();
});

it('describe la regla con una frase legible', function (array $attributes, string $phrase) {
    $rule = new RecurringTaskRule(['starts_on' => '2026-10-05', ...$attributes]);

    expect($this->describer->describe($rule))->toBe($phrase);
})->with([
    'semanal' => [['frequency' => 'weekly', 'interval' => 1, 'weekday' => 1], 'Cada semana, los lunes'],
    'cada 2 semanas' => [['frequency' => 'weekly', 'interval' => 2, 'weekday' => 1], 'Cada 2 semanas, los lunes'],
    'los domingos' => [['frequency' => 'weekly', 'interval' => 3, 'weekday' => 7], 'Cada 3 semanas, los domingos'],
    'mensual' => [['frequency' => 'monthly', 'interval' => 1, 'month_day' => 15], 'Cada mes, el día 15'],
    'día 31' => [['frequency' => 'monthly', 'interval' => 1, 'month_day' => 31], 'Cada mes, el día 31 (o el último)'],
    'día 29 cada 3 meses' => [['frequency' => 'monthly', 'interval' => 3, 'month_day' => 29], 'Cada 3 meses, el día 29 (o el último)'],
]);

it('calcula la próxima fecha', function (array $attributes, string $today, ?string $next) {
    $rule = new RecurringTaskRule(['is_active' => true, ...$attributes]);

    expect($this->describer->nextDate($rule, CarbonImmutable::parse($today)))->toBe($next);
})->with([
    'hoy toca' => [['frequency' => 'weekly', 'weekday' => 1, 'starts_on' => '2026-09-07'], '2026-10-05', '2026-10-05'],
    'hoy ya se generó' => [['frequency' => 'weekly', 'weekday' => 1, 'starts_on' => '2026-09-07', 'last_generated_on' => '2026-10-05'], '2026-10-05', '2026-10-12'],
    'cada 2 semanas desde el inicio' => [['frequency' => 'weekly', 'interval' => 2, 'weekday' => 1, 'starts_on' => '2026-09-28'], '2026-10-06', '2026-10-12'],
    'el 31 en febrero' => [['frequency' => 'monthly', 'month_day' => 31, 'starts_on' => '2026-01-01'], '2026-02-01', '2026-02-28'],
    'el 30 en un año bisiesto' => [['frequency' => 'monthly', 'month_day' => 30, 'starts_on' => '2028-01-01'], '2028-02-01', '2028-02-29'],
    'cada 12 meses' => [['frequency' => 'monthly', 'interval' => 12, 'month_day' => 10, 'starts_on' => '2026-03-10'], '2026-10-05', '2027-03-10'],
    'empieza más adelante' => [['frequency' => 'weekly', 'weekday' => 5, 'starts_on' => '2026-11-01'], '2026-10-05', '2026-11-06'],
    'ya terminó' => [['frequency' => 'weekly', 'weekday' => 1, 'starts_on' => '2026-09-07', 'ends_on' => '2026-09-30'], '2026-10-05', null],
    'desactivada' => [['frequency' => 'weekly', 'weekday' => 1, 'starts_on' => '2026-09-07', 'is_active' => false], '2026-10-05', null],
]);

it('una regla mensual el 31 crea la tarea el último día de los meses cortos', function () {
    $this->travelTo(CarbonImmutable::parse('2026-02-01 09:00:00', 'Europe/Madrid'));
    $this->post($this->url, ($this->data)(['frequency' => 'monthly', 'month_day' => 31, 'weekday' => null, 'starts_on' => '2026-02-01', 'due_offset_days' => 0]))
        ->assertSessionHasNoErrors();

    expect(Task::query()->count())->toBe(0)
        ->and($this->generator->generate(CarbonImmutable::parse('2026-02-28')))->toBe(1)
        ->and(Task::query()->sole()->due_date->toDateString())->toBe('2026-02-28');
});
