<?php

use App\Enums\Permission;
use App\Models\Department;
use App\Models\TaskStatus;
use App\Models\TaskType;
use App\Models\User;
use App\Models\WorkSchedule;

/*
|--------------------------------------------------------------------------
| Matriz de permisos de la administración (SPEC §5 y §14)
|--------------------------------------------------------------------------
| Usuarios: gate manage-users. Departamentos, tipos, estados y ajustes: gate manage-settings.
| Por defecto solo el rol admin tiene esos permisos. Invitado → login; cliente → su portal.
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();
    $this->target = User::factory()->employee()->create();
    $this->schedule = WorkSchedule::factory()->create(['user_id' => $this->target->id, 'valid_from' => now()->addMonth()->toDateString()]);
    $this->department = Department::factory()->create();
    $this->taskType = TaskType::factory()->create();
    $this->status = TaskStatus::query()->where('is_default', false)->firstOrFail();
});

dataset('admin_pages', [
    'usuarios' => [fn () => '/admin/usuarios'],
    'ficha de usuario' => [fn () => "/admin/usuarios/{$this->target->id}"],
    'asistente de baja' => [fn () => "/admin/usuarios/{$this->target->id}/baja"],
    'departamentos' => [fn () => '/admin/departamentos'],
    'tipos de tarea' => [fn () => '/admin/tipos-de-tarea'],
    'estados' => [fn () => '/admin/estados'],
    'ajustes' => [fn () => '/admin/ajustes'],
]);

dataset('admin_actions', [
    'invitar' => [fn () => ['post', '/admin/usuarios']],
    'editar usuario' => [fn () => ['put', "/admin/usuarios/{$this->target->id}"]],
    'reenviar invitación' => [fn () => ['post', "/admin/usuarios/{$this->target->id}/invitacion"]],
    'desactivar' => [fn () => ['post', "/admin/usuarios/{$this->target->id}/baja"]],
    'reactivar' => [fn () => ['post', "/admin/usuarios/{$this->target->id}/reactivar"]],
    'nueva jornada' => [fn () => ['post', "/admin/usuarios/{$this->target->id}/jornadas"]],
    'editar jornada' => [fn () => ['put', "/admin/usuarios/{$this->target->id}/jornadas/{$this->schedule->id}"]],
    'borrar jornada' => [fn () => ['delete', "/admin/usuarios/{$this->target->id}/jornadas/{$this->schedule->id}"]],
    'crear departamento' => [fn () => ['post', '/admin/departamentos']],
    'editar departamento' => [fn () => ['put', "/admin/departamentos/{$this->department->id}"]],
    'borrar departamento' => [fn () => ['delete', "/admin/departamentos/{$this->department->id}"]],
    'crear tipo' => [fn () => ['post', '/admin/tipos-de-tarea']],
    'editar tipo' => [fn () => ['put', "/admin/tipos-de-tarea/{$this->taskType->id}"]],
    'mover tipo' => [fn () => ['post', "/admin/tipos-de-tarea/{$this->taskType->id}/mover"]],
    'borrar tipo' => [fn () => ['delete', "/admin/tipos-de-tarea/{$this->taskType->id}"]],
    'crear estado' => [fn () => ['post', '/admin/estados']],
    'editar estado' => [fn () => ['put', "/admin/estados/{$this->status->id}"]],
    'mover estado' => [fn () => ['post', "/admin/estados/{$this->status->id}/mover"]],
    'borrar estado' => [fn () => ['delete', "/admin/estados/{$this->status->id}"]],
    'guardar ajustes' => [fn () => ['put', '/admin/ajustes']],
]);

test('las páginas de administración solo las abre quien tiene el permiso', function (string $url, string $actor, int $status) {
    if ($actor !== 'guest') {
        $this->actingAs(userWithRole($actor));
    }

    $response = $this->get($url)->assertStatus($status);

    if ($actor === 'guest') {
        $response->assertRedirect(route('login'));
    }

    if ($actor === 'client') {
        $response->assertRedirect(route('portal.home'));
    }
})->with('admin_pages')->with([
    'invitado' => ['guest', 302],
    'admin' => ['admin', 200],
    'responsable' => ['department_manager', 403],
    'empleado' => ['employee', 403],
    'cliente' => ['client', 302],
]);

test('las acciones de administración se rechazan sin permiso y no cambian nada', function (array $request, string $actor, int $status) {
    [$method, $url] = $request;

    if ($actor !== 'guest') {
        $this->actingAs(userWithRole($actor));
    }

    $before = [
        User::query()->count(),
        Department::query()->count(),
        TaskType::query()->count(),
        TaskStatus::query()->count(),
        WorkSchedule::query()->count(),
        $this->target->fresh()?->is_active,
    ];

    $this->json($method, $url, [])->assertStatus($status);

    expect([
        User::query()->count(),
        Department::query()->count(),
        TaskType::query()->count(),
        TaskStatus::query()->count(),
        WorkSchedule::query()->count(),
        $this->target->fresh()?->is_active,
    ])->toBe($before);
})->with('admin_actions')->with([
    'invitado' => ['guest', 401],
    'responsable' => ['department_manager', 403],
    'empleado' => ['employee', 403],
]);

test('un cliente nunca llega a las acciones de administración: acaba en su portal', function (array $request) {
    [$method, $url] = $request;

    $this->actingAs(userWithRole('client'))
        ->call(strtoupper($method), $url)
        ->assertRedirect(route('portal.home'));
})->with('admin_actions');

test('los permisos van por separado: manage-users no abre los catálogos ni manage-settings los usuarios', function () {
    $people = userWithRole('department_manager');
    $people->givePermissionTo(Permission::ManageUsers->value);

    $this->actingAs($people)->get('/admin/usuarios')->assertOk();
    $this->actingAs($people)->get('/admin/ajustes')->assertForbidden();
    $this->actingAs($people)->get('/admin/departamentos')->assertForbidden();

    $settings = userWithRole('department_manager');
    $settings->givePermissionTo(Permission::ManageSettings->value);

    $this->actingAs($settings)->get('/admin/ajustes')->assertOk();
    $this->actingAs($settings)->get('/admin/estados')->assertOk();
    $this->actingAs($settings)->get('/admin/usuarios')->assertForbidden();
});

test('un admin desactivado no entra en la administración', function () {
    $admin = userWithRole('admin', ['is_active' => false]);

    $this->actingAs($admin)->get('/admin/usuarios')->assertRedirect(route('login'));
});
