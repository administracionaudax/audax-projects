<?php

use App\Enums\TimesheetStatus;
use App\Models\Client;
use App\Models\Department;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\TimeEntryLock;
use App\Models\TimesheetPeriod;
use App\Models\User;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Matriz de permisos del área de horas (SPEC §5, D-020, D-021, D-034)
|--------------------------------------------------------------------------
| Cada ruta × invitado, admin, responsable (del departamento de la persona), empleado (otra persona
| del mismo departamento, sin permisos sobre la entrada), gestor (empleado de otro departamento que
| gestiona el proyecto de la persona: D-021, D-036) y cliente. Los recursos (entrada, semana,
| bloqueo) son de una tercera persona del departamento.
| 302 de invitado = al login; 302 de cliente = a /portal; «ok» = 2xx o 302 de vuelta (back()).
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00:00', 'Europe/Madrid'));

    $department = Department::factory()->create();
    $this->owner = User::factory()->employee()->inDepartment($department)->create();
    $this->actors = [
        'guest' => null,
        'admin' => User::factory()->admin()->create(),
        'department_manager' => User::factory()->departmentManager()->inDepartment($department)->create(),
        'employee' => User::factory()->employee()->inDepartment($department)->create(),
        'project_manager' => User::factory()->employee()->inDepartment(Department::factory()->create())->create(),
        'client' => userWithRole('client'),
    ];
    $department->managers()->attach($this->actors['department_manager']);

    $this->project = Project::factory()->create(['client_id' => Client::factory()->create()->id]);
    $this->project->addMember($this->owner);
    $this->project->addMember($this->actors['project_manager'], isManager: true);
    $this->task = Task::factory()->create(['project_id' => $this->project->id]);
    $this->entry = TimeEntry::factory()->forTask($this->task)->on('2026-09-14')->create(['user_id' => $this->owner->id]);
    $this->period = TimesheetPeriod::factory()->for($this->owner)->week('2026-09-21')->status(TimesheetStatus::Submitted)->create();
    $this->approved = TimesheetPeriod::factory()->for($this->owner)->week('2026-09-07')->status(TimesheetStatus::Approved)->create();
    $this->lock = TimeEntryLock::query()->create([
        'project_id' => $this->project->id,
        'date_from' => '2026-08-01',
        'date_to' => '2026-08-31',
        'locked_by' => $this->actors['admin']->id,
    ]);
});

/**
 * [método, URL (con marcadores), datos, [admin, responsable, empleado, gestor]] → 'ok' o código HTTP.
 * Invitado → login y cliente → portal, siempre.
 */
dataset('rutas de horas', [
    'hoja semanal propia' => ['get', '/horas', [], ['ok', 'ok', 'ok', 'ok']],
    'enviar la semana propia' => ['post', '/horas/semana/enviar', ['week' => '2026-W39'], ['ok', 'ok', 'ok', 'ok']],
    'descartar el temporizador propio' => ['delete', '/temporizador', [], ['ok', 'ok', 'ok', 'ok']],
    'hoja de otra persona' => ['get', '/horas?persona={owner}', [], ['ok', 'ok', 403, 'ok']],
    'buscador de tareas' => ['get', '/horas/tareas?q=a', [], ['ok', 'ok', 'ok', 'ok']],
    'buscador para otra persona' => ['get', '/horas/tareas?user_id={owner}', [], ['ok', 'ok', 403, 'ok']],
    'opciones del diálogo' => ['get', '/horas/opciones', [], ['ok', 'ok', 'ok', 'ok']],
    'aprobaciones' => ['get', '/horas/aprobaciones', [], ['ok', 'ok', 403, 403]],
    'aprobar una semana' => ['post', '/horas/aprobaciones/{period}/aprobar', [], ['ok', 'ok', 403, 403]],
    'devolver una semana' => ['post', '/horas/aprobaciones/{period}/devolver', ['comment' => 'Revisa'], ['ok', 'ok', 403, 403]],
    'aprobar varias' => ['post', '/horas/aprobaciones/aprobar', ['periods' => ['{period}']], ['ok', 'ok', 403, 403]],
    'reabrir una aprobada' => ['post', '/horas/semanas/{approved}/reabrir', [], ['ok', 'ok', 403, 403]],
    'bloqueo' => ['get', '/horas/bloqueo', [], ['ok', 403, 403, 403]],
    'vista previa del bloqueo' => ['get', '/horas/bloqueo/vista-previa?project_id={project}&date_from=2026-09-01&date_to=2026-09-30', [], ['ok', 403, 403, 403]],
    'desbloquear' => ['delete', '/horas/bloqueo/{lock}', [], ['ok', 403, 403, 403]],
    'editar la entrada de otro' => ['put', '/horas/entradas/{entry}', ['task_id' => '{task}', 'date' => '2026-09-14', 'minutes' => 30], ['ok', 'ok', 403, 'ok']],
    'borrar la entrada de otro' => ['delete', '/horas/entradas/{entry}', [], ['ok', 'ok', 403, 'ok']],
    'pestaña Horas del proyecto' => ['get', '/proyectos/{project}/horas', [], ['ok', 'ok', 'ok', 'ok']],
]);

test('matriz de permisos de horas', function (string $method, string $url, array $data, array $expected, string $role) {
    $replace = fn (string $value): string => strtr($value, [
        '{owner}' => (string) $this->owner->id,
        '{period}' => (string) $this->period->id,
        '{approved}' => (string) $this->approved->id,
        '{lock}' => (string) $this->lock->id,
        '{entry}' => (string) $this->entry->id,
        '{task}' => (string) $this->task->id,
        '{project}' => (string) $this->project->id,
    ]);
    $url = $replace($url);
    $data = array_map(fn ($value) => is_array($value) ? array_map(fn ($item) => (int) $replace((string) $item), $value) : $replace((string) $value), $data);

    $user = $this->actors[$role];
    $response = ($user === null ? $this : $this->actingAs($user))->{$method}($url, $data);

    if ($role === 'guest') {
        $response->assertRedirect(route('login'));

        return;
    }

    if ($role === 'client') {
        $response->assertRedirect(route('portal.home'));

        return;
    }

    $status = $expected[array_search($role, ['admin', 'department_manager', 'employee', 'project_manager'], true)];

    if ($status === 'ok') {
        expect($response->status())->toBeIn([200, 302])
            ->and($response->headers->get('Location'))->not->toBe(route('login'))
            ->and($response->headers->get('Location'))->not->toBe(route('portal.home'));
        $response->assertSessionHasNoErrors();
    } else {
        $response->assertStatus($status);
    }
})->with('rutas de horas')->with([
    'invitado' => 'guest',
    'admin' => 'admin',
    'responsable' => 'department_manager',
    'empleado' => 'employee',
    'gestor' => 'project_manager',
    'cliente' => 'client',
]);

test('el temporizador y la entrada manual son de cada uno: nadie actúa sobre el temporizador de otro', function () {
    $employee = $this->actors['employee'];
    $this->project->addMember($employee);

    $this->actingAs($employee)->post('/temporizador', ['task_id' => $this->task->id])->assertSessionHasNoErrors();
    $this->actingAs($this->actors['admin'])->delete('/temporizador');

    expect($employee->activeTimer()->exists())->toBeTrue();
});
