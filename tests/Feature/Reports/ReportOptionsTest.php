<?php

use App\Models\Client;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;

/*
| Opciones de la barra de filtros de los informes: solo lo que quien mira puede usar (D-044).
*/

it('un empleado solo recibe sus proyectos y a sí mismo; un admin, todo', function () {
    $design = Department::factory()->create();
    $employee = User::factory()->employee()->create(['department_id' => $design->id]);
    $colleague = User::factory()->employee()->create(['department_id' => $design->id]);
    $mine = Project::factory()->create();
    $mine->addMember($employee);
    $other = Project::factory()->create(['client_id' => Client::factory()]);

    $response = $this->actingAs($employee)->getJson('/informes/opciones')->assertOk();
    expect(collect($response->json('people'))->pluck('id')->all())->toBe([$employee->id])
        ->and(collect($response->json('projects'))->pluck('id')->all())->toBe([$mine->id])
        ->and($response->json('departments'))->toBe([]);

    $admin = User::factory()->admin()->create();
    $all = $this->actingAs($admin)->getJson('/informes/opciones')->assertOk();
    expect(collect($all->json('projects'))->pluck('id')->all())->toContain($mine->id, $other->id)
        ->and(collect($all->json('people'))->pluck('id')->all())->toContain($employee->id, $colleague->id)
        ->and(collect($all->json('departments'))->pluck('id')->all())->toBe([$design->id]);
});

it('un responsable recibe a su equipo y sus departamentos', function () {
    $design = Department::factory()->create();
    $marketing = Department::factory()->create();
    $head = User::factory()->departmentManager()->create();
    $design->managers()->attach($head);
    $member = User::factory()->employee()->create(['department_id' => $design->id]);
    $outsider = User::factory()->employee()->create(['department_id' => $marketing->id]);

    $response = $this->actingAs($head)->getJson('/informes/opciones')->assertOk();
    $people = collect($response->json('people'))->pluck('id')->all();

    expect($people)->toContain($head->id, $member->id)->not->toContain($outsider->id)
        ->and(collect($response->json('departments'))->pluck('id')->all())->toBe([$design->id]);
});

it('los clientes no acceden', function () {
    $this->actingAs(userWithRole('client'))->getJson('/informes/opciones')->assertForbidden();
});

it('hace falta iniciar sesión', function () {
    $this->getJson('/informes/opciones')->assertUnauthorized();
});
