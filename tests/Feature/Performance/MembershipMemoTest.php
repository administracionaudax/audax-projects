<?php

use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;

/*
| PERF-04: las comprobaciones de gestión y pertenencia (managedDepartmentIds, managedProjectIds,
| isMemberOf, isManagerOf) se memorizan durante una petición y se vacían al cambiar miembros,
| gestores o responsables. Fuera de una petición (comandos, jobs, servicios en los tests) se
| consultan siempre.
*/

/** Simula que se está atendiendo una ruta (la memoria solo se usa entonces). */
function routedRequest(): Request
{
    $request = Request::create('/horas');
    $request->setRouteResolver(fn () => new Route('GET', '/horas', fn () => null));
    app()->instance('request', $request);

    return $request;
}

it('en una petición, consulta una sola vez y se vacía al cambiar los gestores o los responsables', function () {
    routedRequest();
    $user = User::factory()->employee()->create();
    $project = Project::factory()->create();

    $queries = 0;
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (str_contains($query->sql, 'project_members') || str_contains($query->sql, 'department_managers')) {
            $queries++;
        }
    });

    expect($user->isManagerOf($project))->toBeFalse()
        ->and($user->isManagerOf($project))->toBeFalse()
        ->and($user->managedProjectIds())->toBe([])
        ->and($user->managedDepartmentIds())->toBe([])
        ->and($user->managedDepartmentIds())->toBe([]);
    expect($queries)->toBe(2);

    // Hacerlo gestor (pivote ProjectMember) vacía la memoria.
    $project->addMember($user, isManager: true);
    expect($user->isManagerOf($project))->toBeTrue()
        ->and($user->isMemberOf($project))->toBeTrue();

    // Y quitarlo, también.
    $project->members()->detach($user->id);
    expect($user->isManagerOf($project))->toBeFalse()
        ->and($user->isMemberOf($project))->toBeFalse();

    // Responsables de departamento: se vacía a mano tras cambiarlos.
    $department = Department::factory()->create();
    $department->managers()->attach($user);
    User::forgetMemberships();
    expect($user->managedDepartmentIds())->toBe([$department->id]);
});

it('fuera de una petición no memoriza', function () {
    $user = User::factory()->employee()->create();
    $project = Project::factory()->create();

    expect($user->isManagerOf($project))->toBeFalse();
    DB::table('project_members')->insert(['project_id' => $project->id, 'user_id' => $user->id, 'is_manager' => true, 'created_at' => now(), 'updated_at' => now()]);
    expect($user->isManagerOf($project))->toBeTrue();
});

it('la hoja semanal de una persona gestora no repite las consultas de gestión ni de la semana', function () {
    $manager = User::factory()->departmentManager()->create();
    $department = Department::factory()->create();
    $department->managers()->attach($manager);
    $this->actingAs($manager)->get('/horas')->assertOk(); // Calienta las cachés.

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->actingAs($manager)->get('/horas')->assertOk();

    $count = fn (string $needle): int => count(array_filter($queries, fn (string $sql): bool => str_contains($sql, $needle)));

    expect($count('inner join "department_managers"'))->toBe(1)
        ->and($count('from "timesheet_periods"'))->toBe(1);
});
