<?php

use App\Console\Commands\NotifyDueTasks;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Notifications\Tasks\TaskAssignedNotification;
use App\Notifications\Tasks\TaskMentionedNotification;
use App\Notifications\Tasks\TasksDueNotification;
use App\Notifications\Tasks\TaskStatusChangedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/*
| Notificaciones de tareas en la app (SPEC §13): destinatarios de asignación, menciones en la
| descripción, cambio de estado y el resumen diario de vencimientos (app:notify-due-tasks).
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();

    $this->actor = userWithRole('employee');
    $this->colleague = User::factory()->employee()->create(['name' => 'Marta']);
    $this->project = Project::factory()->create(['name' => 'App Clínica']);
    $this->project->addMember($this->actor);
    $this->mention = fn (User $user): string => sprintf('<span data-type="mention" data-id="%d" data-label="%s">@%s</span>', $user->id, e($user->name), e($user->name));
});

it('avisa al nuevo responsable (una sola vez) y nunca a quien se asigna a sí mismo', function () {
    Notification::fake();

    $this->actingAs($this->actor)->post("/proyectos/{$this->project->id}/tareas", [
        'title' => 'Diseñar el logo',
        'assignee_user_id' => $this->colleague->id,
        'description' => '<p>'.($this->mention)($this->colleague).'</p>',
    ])->assertSessionHasNoErrors();

    Notification::assertSentToTimes($this->colleague, TaskAssignedNotification::class, 1);
    Notification::assertNotSentTo($this->colleague, TaskMentionedNotification::class);

    $task = Task::query()->where('title', 'Diseñar el logo')->firstOrFail();
    Notification::assertSentTo($this->colleague, TaskAssignedNotification::class, function (TaskAssignedNotification $notification) use ($task): bool {
        $data = $notification->toArray($this->colleague);

        return $data['kind'] === 'task.assigned'
            && $data['url'] === "/proyectos/{$this->project->id}/tareas?tarea={$task->id}"
            && $data['title'] === "{$this->actor->name} te ha asignado «Diseñar el logo»"
            && $data['body'] === 'App Clínica';
    });

    $this->actingAs($this->actor)->post("/proyectos/{$this->project->id}/tareas", [
        'title' => 'Para mí',
        'assignee_user_id' => $this->actor->id,
    ]);
    Notification::assertNotSentTo($this->actor, TaskAssignedNotification::class);

    // Reasignar a la misma persona no vuelve a avisar.
    $this->actingAs($this->actor)->patch("/tareas/{$task->id}", ['assignee_user_id' => $this->colleague->id]);
    Notification::assertSentToTimes($this->colleague, TaskAssignedNotification::class, 1);
});

it('no avisa a responsables desactivados', function () {
    Notification::fake();
    $task = Task::factory()->create(['project_id' => $this->project->id]);
    $this->colleague->update(['is_active' => false]);

    // La validación rechaza asignar a un inactivo: ni se asigna ni se avisa.
    $this->actingAs($this->actor)->patch("/tareas/{$task->id}", ['assignee_user_id' => $this->colleague->id])
        ->assertSessionHasErrors('assignee_user_id');

    Notification::assertNothingSent();
});

it('las menciones en la descripción avisan solo a los mencionados nuevos', function () {
    Notification::fake();
    $luis = User::factory()->employee()->create();
    $task = Task::factory()->create([
        'project_id' => $this->project->id,
        'description' => '<p>'.($this->mention)($this->colleague).'</p>',
    ]);

    $this->actingAs($this->actor)->patch("/tareas/{$task->id}", [
        'description' => '<p>'.($this->mention)($this->colleague).' '.($this->mention)($luis).' '.($this->mention)($this->actor).'</p>',
    ])->assertSessionHasNoErrors();

    Notification::assertSentTo($luis, TaskMentionedNotification::class, fn (TaskMentionedNotification $n): bool => $n->context === TaskMentionedNotification::IN_DESCRIPTION);
    Notification::assertNotSentTo([$this->colleague, $this->actor], TaskMentionedNotification::class);
});

it('el cambio de estado avisa a los seguidores salvo a quien lo cambia', function () {
    Notification::fake();
    $task = Task::factory()->create(['project_id' => $this->project->id, 'title' => 'Maquetar']);
    $task->watchers()->attach([$this->colleague->id, $this->actor->id]);
    $inactive = User::factory()->employee()->inactive()->create();
    $task->watchers()->attach($inactive->id);
    $doing = TaskStatus::query()->where('category', 'in_progress')->orderBy('position')->firstOrFail();

    $this->actingAs($this->actor)->patch("/tareas/{$task->id}", ['status_id' => $doing->id])->assertSessionHasNoErrors();

    Notification::assertSentTo($this->colleague, TaskStatusChangedNotification::class, function (TaskStatusChangedNotification $n): bool {
        $data = $n->toArray($this->colleague);

        return $data['title'] === "«Maquetar» ha pasado a «{$n->statusName}»" && $data['kind'] === 'task.status_changed';
    });
    Notification::assertNotSentTo([$this->actor, $inactive], TaskStatusChangedNotification::class);

    // Cambiar otra cosa no avisa del estado.
    $this->actingAs($this->actor)->patch("/tareas/{$task->id}", ['title' => 'Maquetar la home']);
    Notification::assertSentToTimes($this->colleague, TaskStatusChangedNotification::class, 1);
});

it('las notificaciones se guardan en el canal database con el contrato de la campana', function () {
    $task = Task::factory()->create(['project_id' => $this->project->id]);

    $this->actingAs($this->actor)->patch("/tareas/{$task->id}", ['assignee_user_id' => $this->colleague->id])->assertSessionHasNoErrors();

    $notification = $this->colleague->notifications()->firstOrFail();

    expect($notification->type)->toBe(TaskAssignedNotification::class)
        ->and(array_keys($notification->data))->toBe(['kind', 'title', 'body', 'url', 'icon'])
        ->and($notification->data['icon'])->toBe('user-check');
});

describe('app:notify-due-tasks', function () {
    beforeEach(function () {
        // Madrid: martes 22/09/2026 a las 08:00 (mañana = 23/09).
        $this->travelTo(CarbonImmutable::parse('2026-09-22 08:00:00', 'Europe/Madrid'));
        $this->task = fn (User $user, string $title, ?string $due, array $attributes = []): Task => Task::factory()->assignedTo($user)->create([
            'project_id' => $this->project->id,
            'title' => $title,
            'due_date' => $due,
            ...$attributes,
        ]);
    });

    it('envía un solo resumen por persona con lo que vence mañana y lo vencido', function () {
        Notification::fake();
        ($this->task)($this->colleague, 'Mañana 1', '2026-09-23');
        ($this->task)($this->colleague, 'Mañana 2', '2026-09-23');
        ($this->task)($this->colleague, 'Vencida', '2026-09-20');
        ($this->task)($this->colleague, 'Hoy', '2026-09-22');
        ($this->task)($this->colleague, 'Pasado mañana', '2026-09-24');
        ($this->task)($this->actor, 'Solo vencida', '2026-09-01');

        $this->artisan('app:notify-due-tasks')->assertSuccessful();

        Notification::assertSentToTimes($this->colleague, TasksDueNotification::class, 1);
        Notification::assertSentTo($this->colleague, TasksDueNotification::class, function (TasksDueNotification $n): bool {
            $data = $n->toArray($this->colleague);

            return $n->dueTomorrow === ['Mañana 1', 'Mañana 2']
                && $n->overdue === ['Vencida']
                && $data['title'] === 'Tienes 2 que vencen mañana y 1 vencidas'
                && $data['url'] === '/mis-tareas'
                && $data['body'] === '«Vencida», «Mañana 1», «Mañana 2»';
        });
        Notification::assertSentTo($this->actor, TasksDueNotification::class, fn (TasksDueNotification $n): bool => $n->toArray($this->actor)['title'] === 'Tienes 1 tarea vencida');
    });

    it('no repite el aviso el mismo día (hora de Madrid), pero sí al día siguiente', function () {
        ($this->task)($this->colleague, 'Mañana', '2026-09-23');

        $this->artisan('app:notify-due-tasks')->assertSuccessful();
        $this->artisan('app:notify-due-tasks')->assertSuccessful();
        expect($this->colleague->notifications()->where('type', TasksDueNotification::class)->count())->toBe(1);

        // 23:59 del mismo día en Madrid: sigue siendo «hoy».
        $this->travelTo(CarbonImmutable::parse('2026-09-22 23:59:00', 'Europe/Madrid'));
        $this->artisan('app:notify-due-tasks')->assertSuccessful();
        expect($this->colleague->notifications()->where('type', TasksDueNotification::class)->count())->toBe(1);

        // El día del vencimiento no hay aviso (ni vence mañana ni está vencida); al día siguiente, sí.
        $this->travelTo(CarbonImmutable::parse('2026-09-23 07:00:00', 'Europe/Madrid'));
        $this->artisan('app:notify-due-tasks')->assertSuccessful();
        expect($this->colleague->notifications()->where('type', TasksDueNotification::class)->count())->toBe(1);

        $this->travelTo(CarbonImmutable::parse('2026-09-24 07:00:00', 'Europe/Madrid'));
        $this->artisan('app:notify-due-tasks')->assertSuccessful();
        expect($this->colleague->notifications()->where('type', TasksDueNotification::class)->count())->toBe(2);
    });

    it('escribe el aviso al momento, sin cola: dos ejecuciones antes de que corra Horizon no lo repiten', function () {
        Queue::fake();
        ($this->task)($this->colleague, 'Mañana', '2026-09-23');

        $this->artisan('app:notify-due-tasks')->assertSuccessful();
        $this->artisan('app:notify-due-tasks')->assertSuccessful();

        Queue::assertNothingPushed();
        $notifications = $this->colleague->notifications()->where('type', TasksDueNotification::class)->get();
        expect($notifications)->toHaveCount(1)
            ->and($notifications->first()->created_at->equalTo(now()))->toBeTrue();
    });

    it('no avisa si otra ejecución ya lo ha reclamado hoy, y la base de datos lo evita aunque se vacíe la caché', function () {
        ($this->task)($this->colleague, 'Mañana', '2026-09-23');
        ($this->task)($this->actor, 'Vencida', '2026-09-20');
        Cache::add(NotifyDueTasks::claimKey($this->colleague->id, '2026-09-22'), true, now()->addDay());

        $this->artisan('app:notify-due-tasks')->assertSuccessful();

        expect($this->colleague->notifications()->count())->toBe(0)
            ->and($this->actor->notifications()->where('type', TasksDueNotification::class)->count())->toBe(1);

        Cache::flush();
        $this->artisan('app:notify-due-tasks')->assertSuccessful();

        expect($this->actor->notifications()->where('type', TasksDueNotification::class)->count())->toBe(1)
            ->and($this->colleague->notifications()->where('type', TasksDueNotification::class)->count())->toBe(1);
    });

    it('ignora tareas completadas, sin responsable, de proyectos archivados y a personas inactivas', function () {
        Notification::fake();
        $inactive = User::factory()->employee()->inactive()->create();
        $client = User::factory()->client()->create();
        ($this->task)($this->colleague, 'Hecha', '2026-09-23', ['status_id' => TaskStatus::query()->where('category', 'done')->value('id')]);
        Task::factory()->create(['project_id' => $this->project->id, 'due_date' => '2026-09-23']);
        Task::factory()->assignedTo($this->colleague)->create(['project_id' => Project::factory()->archived()->create()->id, 'due_date' => '2026-09-23']);
        ($this->task)($inactive, 'De una baja', '2026-09-23');
        ($this->task)($client, 'De un cliente', '2026-09-23');

        $this->artisan('app:notify-due-tasks')->assertSuccessful();

        Notification::assertNothingSent();
    });
});
