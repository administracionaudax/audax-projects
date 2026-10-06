<?php

use App\Domain\DayPlan\DayPlanTime;
use App\Domain\Time\TimerService;
use App\Domain\Weeklies\MyWeeklyClients;
use App\Enums\DayPlanItemStatus;
use App\Enums\TimeEntryStatus;
use App\Models\ActiveTimer;
use App\Models\Client;
use App\Models\DayPlanItem;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use App\Models\WeeklyCycle;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Las horas de una línea del plan del día (docs/PLAN-CARGAS.md §6.1.4; D-254): el temporizador desde
| la línea siempre en una tarea, «Imputar lo previsto», vincular horas, imputar a mano y la Weekly.
| Todo con TimerService y TimeEntryWriter. Hoy, miércoles 07/10/2026 a las 10:00 de Madrid.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Europe/Madrid'));
    TaskStatus::ensureDefaults();
    $this->department = Department::factory()->create();
    $this->me = userWithRole('employee', ['department_id' => $this->department->id]);
    $this->client = Client::factory()->create();
    $this->project = Project::factory()->withMembers([$this->me])->create(['client_id' => $this->client->id, 'code' => 'KIWI-CONF']);
    $this->task = Task::factory()->create(['project_id' => $this->project->id, 'title' => 'Configurador · paso 3']);
});

it('▶ en una línea con tarea arranca el temporizador en esa tarea y al parar enlaza las horas', function () {
    $line = DayPlanItem::factory()->create(['user_id' => $this->me->id, 'date' => '2026-10-07', 'task_id' => $this->task->id, 'project_id' => $this->project->id, 'text' => 'JS del configurador']);

    $this->actingAs($this->me)->post("/dia/lineas/{$line->id}/temporizador")->assertSessionHasNoErrors();

    $timer = ActiveTimer::query()->findOrFail($this->me->id);
    expect($timer->task_id)->toBe($this->task->id)->and($timer->day_plan_item_id)->toBe($line->id);

    $this->actingAs($this->me)->get('/dia')->assertInertia(fn (Assert $page) => $page
        ->where('timer.day_plan_item.text', 'JS del configurador')
        ->where('day.running_item_id', $line->id)
        ->where('day.items.0.running', true));

    $this->travel(42)->minutes();
    $this->actingAs($this->me)->post('/temporizador/parar')
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('day_plan_prompt', ['id' => $line->id, 'text' => 'JS del configurador']);

    $entry = TimeEntry::query()->firstOrFail();
    expect($entry->day_plan_item_id)->toBe($line->id)
        ->and($entry->minutes)->toBe(42)
        ->and($entry->status)->toBe(TimeEntryStatus::Draft);

    // Con la línea ya hecha no se pregunta (antes, se pinta la página: el aviso anterior se consume).
    $this->actingAs($this->me)->get('/dia');
    $line->update(['status' => DayPlanItemStatus::Done]);
    $this->actingAs($this->me)->post("/dia/lineas/{$line->id}/temporizador");
    $this->actingAs($this->me)->get('/dia');
    $this->travel(10)->minutes();
    $this->actingAs($this->me)->post('/temporizador/parar')->assertInertiaFlashMissing('day_plan_prompt');
});

it('sin tarea pide elegirla; la elegida se queda en la línea con su proyecto y cliente', function () {
    $line = DayPlanItem::factory()->create(['user_id' => $this->me->id, 'date' => '2026-10-07', 'text' => 'Paso 3']);

    $this->actingAs($this->me)->post("/dia/lineas/{$line->id}/temporizador")->assertSessionHasErrors('task_id');
    expect(ActiveTimer::query()->exists())->toBeFalse();

    $this->actingAs($this->me)->post("/dia/lineas/{$line->id}/temporizador", ['task_id' => $this->task->id])->assertSessionHasNoErrors();

    expect($line->fresh()->task_id)->toBe($this->task->id)
        ->and($line->fresh()->project_id)->toBe($this->project->id)
        ->and($line->fresh()->client_id)->toBe($this->client->id);
});

it('crea la tarea «<texto>» en el proyecto de la línea, asignada a quien la crea', function () {
    $line = DayPlanItem::factory()->create(['user_id' => $this->me->id, 'date' => '2026-10-07', 'project_id' => $this->project->id, 'client_id' => $this->client->id, 'text' => 'Ajustes del menú móvil']);

    $this->actingAs($this->me)->post("/dia/lineas/{$line->id}/temporizador", ['create_task' => true])->assertSessionHasNoErrors();

    $task = Task::query()->where('title', 'Ajustes del menú móvil')->firstOrFail();
    expect($task->project_id)->toBe($this->project->id)
        ->and($task->assignee_user_id)->toBe($this->me->id)
        ->and($line->fresh()->task_id)->toBe($task->id)
        ->and(ActiveTimer::query()->findOrFail($this->me->id)->task_id)->toBe($task->id);
});

it('en un proyecto de bolsas, la tarea nueva va a la bolsa abierta de su departamento', function () {
    $project = Project::factory()->hourBank()->withMembers([$this->me])->create();
    HourBank::factory()->create(['project_id' => $project->id, 'department_id' => null, 'start_date' => '2026-09-01']);
    $mine = HourBank::factory()->create(['project_id' => $project->id, 'department_id' => $this->department->id, 'start_date' => '2026-08-01']);
    $line = DayPlanItem::factory()->create(['user_id' => $this->me->id, 'date' => '2026-10-07', 'project_id' => $project->id, 'text' => 'Banners']);

    $this->actingAs($this->me)->post("/dia/lineas/{$line->id}/temporizador", ['create_task' => true])->assertSessionHasNoErrors();
    expect(Task::query()->where('title', 'Banners')->value('hour_bank_id'))->toBe($mine->id);

    // Con varias bolsas y ninguna de su departamento, se pide elegir una tarea.
    $other = Project::factory()->hourBank()->withMembers([$this->me])->create();
    HourBank::factory()->count(2)->create(['project_id' => $other->id, 'department_id' => Department::factory()]);
    expect(fn () => app(DayPlanTime::class)->defaultBank($this->me, $other))->toThrow(ValidationException::class);
});

it('no se crea ni arranca nada si no puede imputar: las reglas de siempre', function () {
    $foreign = Project::factory()->create();
    $line = DayPlanItem::factory()->create(['user_id' => $this->me->id, 'date' => '2026-10-07', 'project_id' => $foreign->id, 'text' => 'Ajeno']);

    $this->actingAs($this->me)->post("/dia/lineas/{$line->id}/temporizador", ['create_task' => true])->assertSessionHasErrors('task_id');
    expect(Task::query()->where('title', 'Ajeno')->exists())->toBeFalse()
        ->and(ActiveTimer::query()->exists())->toBeFalse();

    // La línea de otra persona, nunca.
    $other = DayPlanItem::factory()->create(['date' => '2026-10-07', 'task_id' => $this->task->id]);
    $this->actingAs($this->me)->post("/dia/lineas/{$other->id}/temporizador")->assertForbidden();
});

it('la misma tarea desde otra línea para y vuelve a empezar; cruzar la medianoche enlaza las dos entradas', function () {
    $a = DayPlanItem::factory()->create(['user_id' => $this->me->id, 'date' => '2026-10-07', 'task_id' => $this->task->id]);
    $b = DayPlanItem::factory()->create(['user_id' => $this->me->id, 'date' => '2026-10-07', 'task_id' => $this->task->id, 'position' => 1]);

    $this->actingAs($this->me)->post("/dia/lineas/{$a->id}/temporizador");
    $this->travel(30)->minutes();
    $this->actingAs($this->me)->post("/dia/lineas/{$b->id}/temporizador");

    expect(TimeEntry::query()->where('day_plan_item_id', $a->id)->sum('minutes'))->toBe(30)
        ->and(ActiveTimer::query()->findOrFail($this->me->id)->day_plan_item_id)->toBe($b->id);

    // De 23:40 a 00:20: dos entradas, las dos de la línea.
    app(TimerService::class)->discard($this->me);
    $this->travelTo(CarbonImmutable::parse('2026-10-07 23:40:00', 'Europe/Madrid'));
    app(TimerService::class)->start($this->me, $this->task, null, $b->id);
    $this->travelTo(CarbonImmutable::parse('2026-10-08 00:20:00', 'Europe/Madrid'));
    Setting::set('allow_future_time_entries', true);
    app(TimerService::class)->stop($this->me);

    expect(TimeEntry::query()->where('day_plan_item_id', $b->id)->orderBy('date')->get(['date', 'minutes'])->map(fn ($entry) => [$entry->date->toDateString(), $entry->minutes])->all())
        ->toBe([['2026-10-07', 20], ['2026-10-08', 20]]);
});

it('«Imputar lo previsto» de una línea y de todas las del día, con TimeEntryWriter', function () {
    $done = DayPlanItem::factory()->done()->create(['user_id' => $this->me->id, 'date' => '2026-10-07', 'task_id' => $this->task->id, 'planned_minutes' => 120]);
    $pending = DayPlanItem::factory()->create(['user_id' => $this->me->id, 'date' => '2026-10-07', 'task_id' => $this->task->id, 'planned_minutes' => 60, 'position' => 1]);

    $this->actingAs($this->me)->get('/dia')->assertInertia(fn (Assert $page) => $page->where('day.summary.loggable', 1));

    $this->actingAs($this->me)->post("/dia/lineas/{$pending->id}/imputar-previsto")->assertSessionHasErrors('item');
    $this->actingAs($this->me)->post("/dia/lineas/{$done->id}/imputar-previsto")->assertSessionHasNoErrors();
    $this->actingAs($this->me)->post("/dia/lineas/{$done->id}/imputar-previsto")->assertSessionHasErrors('item');

    $entry = TimeEntry::query()->where('day_plan_item_id', $done->id)->firstOrFail();
    expect($entry->minutes)->toBe(120)->and($entry->date->toDateString())->toBe('2026-10-07')->and($entry->project_id)->toBe($this->project->id);

    // En bloque: las del día que lo cumplen; una que no se puede (semana enviada) se cuenta aparte.
    $yesterdayTask = Task::factory()->create(['project_id' => $this->project->id]);
    $more = DayPlanItem::factory()->done()->create(['user_id' => $this->me->id, 'date' => '2026-10-07', 'task_id' => $yesterdayTask->id, 'planned_minutes' => 30, 'position' => 2]);
    $this->actingAs($this->me)->post('/dia/imputar-previsto', ['date' => '2026-10-07'])
        ->assertInertiaFlash('toast.message', 'Imputada 1 línea (0:30).');
    expect(TimeEntry::query()->where('day_plan_item_id', $more->id)->sum('minutes'))->toBe(30);
});

it('imputar a mano desde la línea la enlaza y le deja la tarea', function () {
    $line = DayPlanItem::factory()->create(['user_id' => $this->me->id, 'date' => '2026-10-07', 'text' => 'Paso 3']);

    $this->actingAs($this->me)->post('/horas/entradas', ['task_id' => $this->task->id, 'date' => '2026-10-07', 'minutes' => '1:15', 'day_plan_item_id' => $line->id])
        ->assertSessionHasNoErrors();

    expect(TimeEntry::query()->value('day_plan_item_id'))->toBe($line->id)
        ->and($line->fresh()->task_id)->toBe($this->task->id);

    // La línea de otra persona o un día cerrado, no.
    $other = DayPlanItem::factory()->create(['date' => '2026-10-07']);
    $this->actingAs($this->me)->post('/horas/entradas', ['task_id' => $this->task->id, 'date' => '2026-10-07', 'minutes' => 30, 'day_plan_item_id' => $other->id])
        ->assertSessionHasErrors('day_plan_item_id');
    $old = DayPlanItem::factory()->create(['user_id' => $this->me->id, 'date' => '2026-09-30']);
    $this->actingAs($this->me)->post('/horas/entradas', ['task_id' => $this->task->id, 'date' => '2026-10-07', 'minutes' => 30, 'day_plan_item_id' => $old->id])
        ->assertSessionHasErrors('day_plan_item_id');
});

it('vincula mis horas del día sin línea (y las quita), nunca las de otra línea ni las bloqueadas', function () {
    $line = DayPlanItem::factory()->create(['user_id' => $this->me->id, 'date' => '2026-10-07']);
    $otherLine = DayPlanItem::factory()->create(['user_id' => $this->me->id, 'date' => '2026-10-07', 'position' => 1]);
    $free = TimeEntry::factory()->create(['user_id' => $this->me->id, 'task_id' => $this->task->id, 'project_id' => $this->project->id, 'date' => '2026-10-07', 'minutes' => 45]);
    $taken = TimeEntry::factory()->create(['user_id' => $this->me->id, 'task_id' => $this->task->id, 'project_id' => $this->project->id, 'date' => '2026-10-07', 'minutes' => 15, 'day_plan_item_id' => $otherLine->id]);
    TimeEntry::factory()->create(['user_id' => $this->me->id, 'task_id' => $this->task->id, 'project_id' => $this->project->id, 'date' => '2026-10-06', 'minutes' => 15]);

    $this->actingAs($this->me)->getJson("/dia/lineas/{$line->id}/entradas")
        ->assertOk()
        ->assertJsonCount(1, 'entries')
        ->assertJsonPath('entries.0.id', $free->id)
        ->assertJsonPath('entries.0.linked', false);

    $this->actingAs($this->me)->post("/dia/lineas/{$line->id}/vincular", ['entry_ids' => [$free->id]])->assertSessionHasNoErrors();
    expect($free->fresh()->day_plan_item_id)->toBe($line->id)
        ->and($line->fresh()->task_id)->toBe($this->task->id);

    $this->actingAs($this->me)->post("/dia/lineas/{$line->id}/vincular", ['entry_ids' => [$taken->id]])->assertSessionHasErrors('entry_ids');

    $this->actingAs($this->me)->post("/dia/lineas/{$line->id}/vincular", ['entry_ids' => []])->assertSessionHasNoErrors();
    expect($free->fresh()->day_plan_item_id)->toBeNull()
        ->and($free->fresh()->minutes)->toBe(45);
});

it('«Añadir a mi día» desde Mis tareas, para hoy o mañana', function () {
    $this->actingAs($this->me)->post('/dia/desde-tareas', ['date' => '2026-10-08', 'task_ids' => [$this->task->id]])
        ->assertInertiaFlash('toast.message', '1 tarea añadida a tu día (mañana).');

    expect(DayPlanItem::query()->where('date', '2026-10-08')->value('task_id'))->toBe($this->task->id);
});

it('el Autocompletar de «Mi weekly» trae las líneas de mi plan de la semana por cliente', function () {
    $cycle = WeeklyCycle::factory()->active('2026-10-05')->create();
    $line = DayPlanItem::factory()->done()->create(['user_id' => $this->me->id, 'date' => '2026-10-06', 'client_id' => $this->client->id, 'project_id' => $this->project->id, 'task_id' => $this->task->id, 'text' => 'Creatividades campaña otoño']);
    TimeEntry::factory()->create(['user_id' => $this->me->id, 'task_id' => $this->task->id, 'project_id' => $this->project->id, 'date' => '2026-10-06', 'minutes' => 130, 'day_plan_item_id' => $line->id]);
    DayPlanItem::factory()->create(['user_id' => $this->me->id, 'date' => '2026-10-07', 'text' => 'Reunión interna']);
    DayPlanItem::factory()->create(['user_id' => $this->me->id, 'date' => '2026-10-07', 'text' => 'Pasada', 'status' => DayPlanItemStatus::Carried, 'position' => 1]);

    $autofill = app(MyWeeklyClients::class)->autofill($this->me, $cycle);

    // La tarea de la línea no se repite como tarea imputada.
    expect($autofill[$this->client->id])->toBe('Creatividades campaña otoño (hecha, 2 h 10 min)')
        ->and($autofill['general'])->toBe('Reunión interna (pendiente)');

    Setting::set('modules', ['day_plan' => false]);
    expect(app(MyWeeklyClients::class)->autofill($this->me, $cycle)[$this->client->id])->toContain('Configurador · paso 3');
});

it('la vista Día del calendario por personas trae el plan de cada una con sus permisos', function () {
    $colleague = userWithRole('employee', ['department_id' => $this->department->id]);
    $this->project->addMember($colleague);
    DayPlanItem::factory()->create(['user_id' => $colleague->id, 'date' => '2026-10-07', 'text' => 'Moodboard', 'planned_minutes' => 60]);

    $this->actingAs($this->me)->get('/calendario?vista=dia&personas=1&fecha=2026-10-07')
        ->assertOk()
        ->assertInertia(function (Assert $page) use ($colleague) {
            $plans = $page->toArray()['props']['calendar']['day_plans'];

            expect($plans[$colleague->id][0]['text'])->toBe('Moodboard')
                ->and($plans[$colleague->id][0]['planned_minutes'])->toBeNull();
        });

    $this->actingAs($this->me)->get('/calendario?vista=semana&personas=1')
        ->assertInertia(fn (Assert $page) => $page->where('calendar.day_plans', null));
});
