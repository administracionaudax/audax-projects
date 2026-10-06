<?php

use App\Enums\DayPlanItemStatus;
use App\Enums\TaskStatusCategory;
use App\Models\DayPlanItem;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Mi día (`/dia`, docs/PLAN-CARGAS.md §4.3 y §6.1; D-250 y D-251): la página, sus acciones por HTTP y
| quién entra. Hoy, miércoles 07/10/2026 a las 10:00 de Madrid.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Europe/Madrid'));
    TaskStatus::ensureDefaults();
    $this->me = userWithRole('employee');
});

it('pinta mi día con sus cifras: jornada, previsto (sin las pasadas), imputado del día y hechas', function () {
    $project = Project::factory()->withMembers([$this->me])->create();
    $task = Task::factory()->create(['project_id' => $project->id]);
    $done = DayPlanItem::factory()->done()->create(['user_id' => $this->me->id, 'date' => '2026-10-07', 'planned_minutes' => 60, 'task_id' => $task->id, 'project_id' => $project->id, 'position' => 0]);
    DayPlanItem::factory()->create(['user_id' => $this->me->id, 'date' => '2026-10-07', 'planned_minutes' => 120, 'position' => 1]);
    DayPlanItem::factory()->create(['user_id' => $this->me->id, 'date' => '2026-10-07', 'planned_minutes' => 30, 'status' => DayPlanItemStatus::Carried, 'position' => 2]);
    TimeEntry::factory()->create(['user_id' => $this->me->id, 'task_id' => $task->id, 'project_id' => $project->id, 'date' => '2026-10-07', 'minutes' => 65, 'day_plan_item_id' => $done->id]);
    TimeEntry::factory()->create(['user_id' => $this->me->id, 'task_id' => $task->id, 'project_id' => $project->id, 'date' => '2026-10-07', 'minutes' => 15]);

    $this->actingAs($this->me)->get('/dia')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('day-plan/index')
            ->where('day.date', '2026-10-07')
            ->where('day.can.write', true)
            ->where('day.summary.capacity_minutes', 480)
            ->where('day.summary.planned_minutes', 180)
            ->where('day.summary.logged_minutes', 80)
            ->where('day.summary.done', 1)
            ->where('day.summary.total', 3)
            ->has('day.items', 3)
            ->where('day.items.0.logged_minutes', 65)
            ->where('day.items.0.task.id', $task->id)
            ->where('day.items.0.project.code', $project->code)
            ->missing('targets'));
});

it('propone las pendientes de los días que aún se cierran, solo al mirar hoy', function () {
    DayPlanItem::factory()->create(['user_id' => $this->me->id, 'date' => '2026-10-06', 'text' => 'Ayer']);
    DayPlanItem::factory()->create(['user_id' => $this->me->id, 'date' => '2026-10-05', 'text' => 'Anteayer (fuera de plazo)']);
    DayPlanItem::factory()->done()->create(['user_id' => $this->me->id, 'date' => '2026-10-06']);

    $this->actingAs($this->me)->get('/dia')
        ->assertInertia(fn (Assert $page) => $page
            ->has('day.pending', 1)
            ->where('day.pending.0.text', 'Ayer'));

    $this->actingAs($this->me)->get('/dia?fecha=2026-10-06')
        ->assertInertia(fn (Assert $page) => $page
            ->where('day.date', '2026-10-06')
            ->where('day.can.write', false)
            ->where('day.can.close', true)
            ->where('day.pending', []));
});

it('escribe, cierra, pasa a hoy y ordena por HTTP', function () {
    $this->actingAs($this->me)
        ->post('/dia/lineas', ['date' => '2026-10-07', 'text' => 'Moodboard', 'planned_minutes' => '1:30'])
        ->assertSessionHasNoErrors();

    $line = DayPlanItem::query()->firstOrFail();
    expect($line->planned_minutes)->toBe(90);

    $this->actingAs($this->me)->post("/dia/lineas/{$line->id}/estado", ['status' => 'done'])->assertSessionHasNoErrors();
    expect($line->fresh()->status)->toBe(DayPlanItemStatus::Done);

    $old = DayPlanItem::factory()->create(['user_id' => $this->me->id, 'date' => '2026-10-06']);
    $this->actingAs($this->me)->post('/dia/pendientes/pasar', ['ids' => [$old->id]])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', '1 línea pasada a hoy.');
    $copy = DayPlanItem::query()->where('carried_from_id', $old->id)->firstOrFail();

    $this->actingAs($this->me)->put('/dia/orden', ['date' => '2026-10-07', 'ids' => [$copy->id, $line->id]])->assertSessionHasNoErrors();
    expect($copy->fresh()->position)->toBe(0)->and($line->fresh()->position)->toBe(1);

    $this->actingAs($this->me)->put('/dia/nota', ['date' => '2026-10-07', 'note' => 'Médico a las 12'])->assertSessionHasNoErrors();
    $this->actingAs($this->me)->get('/dia')->assertInertia(fn (Assert $page) => $page->where('day.plan.note', 'Médico a las 12'));
});

it('al marcarla hecha, la tarea de la línea solo se completa si se pide', function () {
    $project = Project::factory()->withMembers([$this->me])->create();
    $task = Task::factory()->create(['project_id' => $project->id]);
    $line = DayPlanItem::factory()->create(['user_id' => $this->me->id, 'date' => '2026-10-07', 'task_id' => $task->id, 'project_id' => $project->id]);

    $this->actingAs($this->me)->post("/dia/lineas/{$line->id}/estado", ['status' => 'done']);
    expect($task->fresh()->completed_at)->toBeNull();

    $this->actingAs($this->me)->post("/dia/lineas/{$line->id}/estado", ['status' => 'done', 'complete_task' => true]);
    expect($task->fresh()->completed_at)->not->toBeNull()
        ->and($task->fresh()->status->category)->toBe(TaskStatusCategory::Done);
});

it('añade líneas desde mis tareas sin repetirlas', function () {
    $project = Project::factory()->withMembers([$this->me])->create();
    $due = Task::factory()->create(['project_id' => $project->id, 'assignee_user_id' => $this->me->id, 'due_date' => '2026-10-07', 'title' => 'Entregar banners']);
    $other = Task::factory()->create(['project_id' => $project->id, 'assignee_user_id' => $this->me->id, 'due_date' => '2026-12-01']);

    $this->actingAs($this->me)->getJson('/dia/tareas-sugeridas?fecha=2026-10-07')
        ->assertOk()
        ->assertJsonPath('tasks.0.id', $due->id)
        ->assertJsonPath('tasks.0.reason', 'due')
        ->assertJsonMissing(['id' => $other->id]);

    $this->actingAs($this->me)->post('/dia/desde-tareas', ['date' => '2026-10-07', 'task_ids' => [$due->id]])->assertSessionHasNoErrors();
    $this->actingAs($this->me)->post('/dia/desde-tareas', ['date' => '2026-10-07', 'task_ids' => [$due->id]])
        ->assertInertiaFlash('toast.message', __('day_plan.flash.from_tasks_none'));

    expect(DayPlanItem::query()->where('task_id', $due->id)->count())->toBe(1)
        ->and(DayPlanItem::query()->value('text'))->toBe('Entregar banners');

    $this->actingAs($this->me)->getJson('/dia/tareas-sugeridas?fecha=2026-10-07')->assertJsonCount(0, 'tasks');
});

it('nadie toca las líneas de otro por HTTP', function () {
    $line = DayPlanItem::factory()->create(['date' => '2026-10-07']);

    foreach ([userWithRole('admin'), userWithRole('department_manager'), $this->me] as $user) {
        $this->actingAs($user)->post("/dia/lineas/{$line->id}/estado", ['status' => 'done'])->assertForbidden();
        $this->actingAs($user)->patch("/dia/lineas/{$line->id}", ['text' => 'otra'])->assertForbidden();
        $this->actingAs($user)->delete("/dia/lineas/{$line->id}")->assertForbidden();
    }

    expect($line->fresh()->status)->toBe(DayPlanItemStatus::Pending);
});

it('los colaboradores externos y los clientes no entran', function () {
    $this->actingAs(userWithRole('collaborator'))->get('/dia')->assertForbidden();
    $this->actingAs(userWithRole('collaborator'))->post('/dia/lineas', ['date' => '2026-10-07', 'text' => 'x'])->assertForbidden();
    $this->actingAs(userWithRole('client'))->get('/dia')->assertRedirect();
});

it('con el módulo apagado no existe, salvo para los admins en modo de prueba', function () {
    Setting::set('modules', ['day_plan' => false]);
    $admin = userWithRole('admin');

    $this->actingAs($this->me)->get('/dia')->assertNotFound();
    $this->actingAs($admin)->get('/dia')->assertNotFound();
    $this->actingAs($this->me)->get('/')->assertInertia(fn (Assert $page) => $page->where('auth.can.useDayPlan', false));

    Setting::set('modules_preview', true);
    $this->actingAs($admin)->get('/dia')->assertOk()->assertInertia(fn (Assert $page) => $page->where('module_preview', true));
    $this->actingAs($this->me)->get('/dia')->assertNotFound();
});

it('Inicio trae la tarjeta «Mi día» (diferida) y la habilidad de la navegación', function () {
    DayPlanItem::factory()->done()->create(['user_id' => $this->me->id, 'date' => '2026-10-07']);
    DayPlanItem::factory()->create(['user_id' => $this->me->id, 'date' => '2026-10-07']);

    $this->actingAs($this->me)->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.can.useDayPlan', true)
            ->missing('day_plan')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('day_plan.done', 1)
                ->where('day_plan.total', 2)
                ->has('day_plan.items', 2)));

    $this->actingAs(userWithRole('collaborator'))->get('/')
        ->assertInertia(fn (Assert $page) => $page->missing('day_plan')->where('auth.can.useDayPlan', false));
});

it('la búsqueda global encuentra Mi día solo a quien lo usa', function () {
    $this->actingAs($this->me)->getJson('/buscar?q=mi%20dia')->assertOk()->assertJsonFragment(['url' => '/dia']);

    Setting::set('modules', ['day_plan' => false]);
    $this->actingAs($this->me)->getJson('/buscar?q=mi%20dia')->assertJsonMissing(['url' => '/dia']);
});
