<?php

use App\Enums\Permission;
use App\Enums\TimesheetStatus;
use App\Models\ActiveTimer;
use App\Models\Department;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\TimesheetPeriod;
use App\Models\User;
use App\Notifications\Tasks\TaskAssignedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Baja de un usuario con asistente (SPEC §14): reasignar tareas, parar el temporizador, dejar de
| ser responsable y cerrar sesiones. Nunca se borra a nadie ni se tocan sus horas.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 10:30:00', 'Europe/Madrid'));

    $this->admin = userWithRole('admin');
    $this->leaving = userWithRole('employee', ['name' => 'Laura Baja']);
    $this->colleague = userWithRole('employee', ['name' => 'Marc Sigue']);
    $this->project = Project::factory()->withMembers([$this->leaving])->create();
    $this->other = Project::factory()->withMembers([$this->leaving, $this->colleague])->create();

    $this->taskA = Task::factory()->assignedTo($this->leaving)->create(['project_id' => $this->project->id, 'title' => 'Maquetar la home']);
    $this->taskB = Task::factory()->assignedTo($this->leaving)->create(['project_id' => $this->other->id, 'title' => 'Revisar textos']);
    $this->taskC = Task::factory()->assignedTo($this->leaving)->create(['project_id' => $this->other->id, 'title' => 'Preparar reunión']);
    $this->done = Task::factory()->assignedTo($this->leaving)->completed()->create(['project_id' => $this->project->id]);
    $this->foreign = Task::factory()->assignedTo($this->colleague)->create(['project_id' => $this->other->id]);
});

test('el asistente enseña las tareas abiertas, el temporizador, lo que dirige y a quién se pueden pasar', function () {
    $department = Department::factory()->create(['name' => 'Diseño']);
    $department->managers()->attach($this->leaving);
    $this->project->forceFill(['owner_user_id' => $this->leaving->id])->save();
    userWithRole('employee', ['is_active' => false]);
    userWithRole('client');
    ActiveTimer::query()->create(['user_id' => $this->leaving->id, 'task_id' => $this->taskA->id, 'started_at' => now()->subMinutes(45)]);

    $this->actingAs($this->admin)
        ->get("/admin/usuarios/{$this->leaving->id}/baja")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/users/deactivate')
            ->where('blocked', null)
            ->has('tasks', 3)
            ->where('tasks.0.project.id', fn ($id) => in_array($id, [$this->project->id, $this->other->id], true))
            ->where('timer.task_title', 'Maquetar la home')
            ->where('timer.elapsed_minutes', 45)
            ->where('candidates', function ($candidates) {
                $ids = collect($candidates)->pluck('id');

                // Personas internas activas (también los gestores de los proyectos), nunca quien se
                // va, las desactivadas ni los clientes.
                return $ids->contains($this->admin->id)
                    && $ids->contains($this->colleague->id)
                    && ! $ids->contains($this->leaving->id)
                    && User::query()->whereKey($ids)->where(fn ($query) => $query->where('is_active', false)->orWhereHas('roles', fn ($roles) => $roles->where('name', 'client')))->doesntExist();
            })
            ->where('managedDepartments', ['Diseño'])
            ->has('ownedProjects', 1));
});

test('desactiva: reasigna las tareas (una a una y el resto en bloque), para el temporizador e imputa, y cierra sus sesiones', function () {
    config(['session.driver' => 'database']);
    insertSession($this->leaving);
    $department = Department::factory()->create();
    $department->managers()->attach($this->leaving);
    ActiveTimer::query()->create([
        'user_id' => $this->leaving->id,
        'task_id' => $this->taskA->id,
        'started_at' => CarbonImmutable::parse('2026-09-24 09:00:00', 'Europe/Madrid')->utc(),
        'description' => 'Cabecera',
    ]);

    $this->actingAs($this->admin)
        ->post("/admin/usuarios/{$this->leaving->id}/baja", [
            'default_assignee_id' => $this->colleague->id,
            'assignments' => [
                ['task_id' => $this->taskB->id, 'assignee_user_id' => null],
                ['task_id' => $this->taskC->id, 'assignee_user_id' => $this->admin->id],
            ],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect("/admin/usuarios/{$this->leaving->id}");

    expect($this->leaving->fresh()?->is_active)->toBeFalse()
        ->and($this->taskA->fresh()?->assignee_user_id)->toBe($this->colleague->id)
        ->and($this->taskB->fresh()?->assignee_user_id)->toBeNull()
        ->and($this->taskC->fresh()?->assignee_user_id)->toBe($this->admin->id)
        // Las completadas y las de otras personas no se tocan.
        ->and($this->done->fresh()?->assignee_user_id)->toBe($this->leaving->id)
        ->and($this->foreign->fresh()?->assignee_user_id)->toBe($this->colleague->id)
        ->and(ActiveTimer::query()->count())->toBe(0)
        ->and($department->managers()->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $this->leaving->id)->exists())->toBeFalse();

    $entry = TimeEntry::query()->where('user_id', $this->leaving->id)->sole();
    expect($entry->minutes)->toBe(90)
        ->and($entry->task_id)->toBe($this->taskA->id)
        ->and($entry->description)->toBe('Cabecera');

    // Quien recibe una tarea de un proyecto del que no era miembro pasa a serlo (sin ser gestor).
    expect($this->colleague->fresh()?->isMemberOf($this->project))->toBeTrue()
        ->and($this->colleague->fresh()?->isManagerOf($this->project))->toBeFalse()
        ->and($this->admin->fresh()?->isMemberOf($this->other))->toBeTrue();

    // Y cada reasignación queda en la auditoría de la tarea.
    expect(DB::table('activity_log')->where('subject_type', (new Task)->getMorphClass())->where('subject_id', $this->taskA->id)->where('event', 'updated')->exists())->toBeTrue();
});

test('quien recibe las tareas de la baja pasa a seguirlas y recibe el aviso de asignación, como al asignar desde la tarea', function () {
    $this->taskB->watchers()->attach($this->colleague->id);

    $this->actingAs($this->admin)
        ->post("/admin/usuarios/{$this->leaving->id}/baja", [
            'default_assignee_id' => $this->colleague->id,
            'assignments' => [
                ['task_id' => $this->taskC->id, 'assignee_user_id' => $this->admin->id],
            ],
        ])
        ->assertSessionHasNoErrors();

    // Sigue las tareas nuevas (A y B; la B ya la seguía) y no se duplica.
    expect($this->taskA->watchers()->whereKey($this->colleague->id)->exists())->toBeTrue()
        ->and($this->taskB->watchers()->whereKey($this->colleague->id)->count())->toBe(1)
        ->and($this->taskC->watchers()->whereKey($this->admin->id)->exists())->toBeTrue();

    // Un aviso de asignación por tarea recibida; quien hace la baja no se avisa a sí mismo.
    $notifications = $this->colleague->fresh()->notifications()->where('type', TaskAssignedNotification::class)->get();
    expect($notifications)->toHaveCount(2)
        ->and($notifications->pluck('data.url')->sort()->values()->all())->toBe(["/tareas/{$this->taskA->id}", "/tareas/{$this->taskB->id}"])
        ->and($this->admin->fresh()->notifications()->count())->toBe(0);
});

test('una baja rechazada no avisa a nadie', function () {
    // Nadie se da de baja a sí mismo: no se reasigna nada ni sale ningún aviso.
    $this->actingAs($this->admin)
        ->post("/admin/usuarios/{$this->admin->id}/baja", ['default_assignee_id' => $this->colleague->id])
        ->assertSessionHasErrors();

    expect(DB::table('notifications')->count())->toBe(0);
});

test('si el temporizador no se puede imputar, se descarta y se avisa; la baja sigue adelante', function () {
    ActiveTimer::query()->create([
        'user_id' => $this->leaving->id,
        'task_id' => $this->taskA->id,
        'started_at' => CarbonImmutable::parse('2026-09-24 09:00:00', 'Europe/Madrid')->utc(),
    ]);
    TimesheetPeriod::factory()->for($this->leaving)->week('2026-09-24')->status(TimesheetStatus::Submitted)->create();

    $this->actingAs($this->admin)
        ->post("/admin/usuarios/{$this->leaving->id}/baja", ['default_assignee_id' => null])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.type', 'warning');

    expect((string) data_get(session()->get(SessionKey::FLASH_DATA), 'toast.message'))->toContain('se ha descartado')
        ->and($this->leaving->fresh()?->is_active)->toBeFalse()
        ->and(ActiveTimer::query()->count())->toBe(0)
        ->and(TimeEntry::query()->count())->toBe(0)
        ->and(Task::query()->open()->assignedTo($this->leaving)->count())->toBe(0);
});

test('un temporizador más corto que el redondeo se descarta sin imputar', function () {
    ActiveTimer::query()->create([
        'user_id' => $this->leaving->id,
        'task_id' => $this->taskA->id,
        'started_at' => now()->subSeconds(20),
    ]);

    $this->actingAs($this->admin)
        ->post("/admin/usuarios/{$this->leaving->id}/baja", [])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.type', 'success');

    expect(ActiveTimer::query()->count())->toBe(0)->and(TimeEntry::query()->count())->toBe(0);
});

test('sus horas se conservan intactas', function () {
    $entry = TimeEntry::factory()->forTask($this->taskA)->create(['user_id' => $this->leaving->id, 'date' => '2026-09-22', 'minutes' => 120]);

    $this->actingAs($this->admin)->post("/admin/usuarios/{$this->leaving->id}/baja", [])->assertSessionHasNoErrors();

    expect($entry->fresh()?->minutes)->toBe(120)
        ->and($entry->fresh()?->user_id)->toBe($this->leaving->id)
        ->and(User::query()->whereKey($this->leaving->id)->exists())->toBeTrue();
});

test('solo se reasigna a personas internas activas y nunca a quien se da de baja', function (Closure $assignee) {
    $target = $assignee->call($this);

    $this->actingAs($this->admin)
        ->post("/admin/usuarios/{$this->leaving->id}/baja", [
            'assignments' => [['task_id' => $this->taskA->id, 'assignee_user_id' => $target]],
        ])
        ->assertSessionHasErrors('assignments.0.assignee_user_id');

    expect($this->leaving->fresh()?->is_active)->toBeTrue()
        ->and($this->taskA->fresh()?->assignee_user_id)->toBe($this->leaving->id);
})->with([
    'desactivada' => [fn () => userWithRole('employee', ['is_active' => false])->id],
    'cliente' => [fn () => userWithRole('client')->id],
    'la misma persona' => [fn () => $this->leaving->id],
    'inexistente' => [fn () => 999999],
]);

test('traspasa la gestión principal de los proyectos elegidos; los demás siguen igual', function () {
    $this->project->forceFill(['owner_user_id' => $this->leaving->id])->save();
    $this->other->forceFill(['owner_user_id' => $this->leaving->id])->save();
    $archived = Project::factory()->archived()->create(['owner_user_id' => $this->leaving->id]);
    $foreign = Project::factory()->create();
    $foreignOwner = $foreign->owner_user_id;

    $this->actingAs($this->admin)
        ->post("/admin/usuarios/{$this->leaving->id}/baja", [
            'owners' => [
                ['project_id' => $this->project->id, 'owner_user_id' => $this->colleague->id],
                ['project_id' => $this->other->id, 'owner_user_id' => null],
                // Ni un proyecto archivado ni uno que no dirige cambian, aunque lleguen.
                ['project_id' => $archived->id, 'owner_user_id' => $this->colleague->id],
                ['project_id' => $foreign->id, 'owner_user_id' => $this->colleague->id],
            ],
        ])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.type', 'success');

    expect($this->leaving->fresh()?->is_active)->toBeFalse()
        ->and($this->project->fresh()?->owner_user_id)->toBe($this->colleague->id)
        ->and($this->colleague->fresh()?->isManagerOf($this->project))->toBeTrue()
        ->and($this->other->fresh()?->owner_user_id)->toBe($this->leaving->id)
        ->and($archived->fresh()?->owner_user_id)->toBe($this->leaving->id)
        ->and($foreign->fresh()?->owner_user_id)->toBe($foreignOwner)
        ->and((string) data_get(session()->get(SessionKey::FLASH_DATA), 'toast.message'))->toContain('1 proyecto tiene un gestor principal nuevo')
        ->and(DB::table('activity_log')->where('subject_type', (new Project)->getMorphClass())->where('subject_id', $this->project->id)->where('event', 'updated')->exists())->toBeTrue();
});

test('el nuevo gestor principal es una persona interna activa y no quien se da de baja', function (Closure $owner) {
    $this->project->forceFill(['owner_user_id' => $this->leaving->id])->save();

    $this->actingAs($this->admin)
        ->post("/admin/usuarios/{$this->leaving->id}/baja", [
            'owners' => [['project_id' => $this->project->id, 'owner_user_id' => $owner->call($this)]],
        ])
        ->assertSessionHasErrors('owners.0.owner_user_id');

    expect($this->leaving->fresh()?->is_active)->toBeTrue()
        ->and($this->project->fresh()?->owner_user_id)->toBe($this->leaving->id);
})->with([
    'desactivada' => [fn () => userWithRole('employee', ['is_active' => false])->id],
    'cliente' => [fn () => userWithRole('client')->id],
    'la misma persona' => [fn () => $this->leaving->id],
]);

test('nadie se desactiva a sí mismo ni desactiva al último admin activo', function () {
    $this->actingAs($this->admin)
        ->post("/admin/usuarios/{$this->admin->id}/baja", [])
        ->assertSessionHasErrors('user');

    $this->actingAs($this->admin)
        ->get("/admin/usuarios/{$this->admin->id}/baja")
        ->assertInertia(fn (Assert $page) => $page->whereNot('blocked', null));

    // Otro admin (con permiso) tampoco puede desactivar al único admin activo.
    $second = userWithRole('admin', ['is_active' => false]);
    expect($second->is_active)->toBeFalse();

    $manager = userWithRole('department_manager');
    $manager->givePermissionTo(Permission::ManageUsers->value);

    $this->actingAs($manager)
        ->post("/admin/usuarios/{$this->admin->id}/baja", [])
        ->assertForbidden();

    expect($this->admin->fresh()?->is_active)->toBeTrue();
});

test('con dos admins activos, uno puede desactivar al otro', function () {
    $second = userWithRole('admin');

    $this->actingAs($this->admin)
        ->post("/admin/usuarios/{$second->id}/baja", [])
        ->assertSessionHasNoErrors();

    expect($second->fresh()?->is_active)->toBeFalse();

    $this->actingAs($this->admin)
        ->post("/admin/usuarios/{$this->admin->id}/baja", [])
        ->assertSessionHasErrors('user');
});

test('reactivar devuelve el acceso; si su departamento se borró, queda sin departamento', function () {
    $department = Department::factory()->create();
    $user = userWithRole('employee', ['is_active' => false, 'department_id' => $department->id]);
    $department->delete();

    $this->actingAs($this->admin)
        ->post("/admin/usuarios/{$user->id}/reactivar")
        ->assertInertiaFlash('toast.type', 'success');

    $user->refresh();
    expect($user->is_active)->toBeTrue()->and($user->department_id)->toBeNull();

    // Una persona desactivada no ve el asistente: vuelve a su ficha.
    $inactive = userWithRole('employee', ['is_active' => false]);
    $this->actingAs($this->admin)
        ->get("/admin/usuarios/{$inactive->id}/baja")
        ->assertRedirect("/admin/usuarios/{$inactive->id}");
});
