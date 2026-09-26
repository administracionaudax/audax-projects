<?php

use App\Models\Department;
use App\Models\HourBank;
use App\Models\TaskType;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Departamentos (SPEC §4.1 y §14, D-024): nombre único, color de la paleta, varios responsables
| (internos activos con rol de responsable o admin) y borrado lógico con condiciones.
*/

beforeEach(function () {
    $this->admin = userWithRole('admin');
});

test('lista los departamentos con sus responsables, personas activas y bolsas abiertas, sin N+1', function () {
    $design = Department::factory()->create(['name' => 'Diseño', 'color' => '#0171FF']);
    $dev = Department::factory()->create(['name' => 'Desarrollo', 'color' => '#179FA5']);
    $manager = userWithRole('department_manager', ['name' => 'Raúl']);
    $coManager = userWithRole('department_manager', ['name' => 'Bea']);
    $design->managers()->attach([$manager->id, $coManager->id]);
    $dev->managers()->attach($manager->id);
    User::factory()->employee()->inDepartment($design)->count(2)->create();
    User::factory()->employee()->inDepartment($design)->inactive()->create();
    HourBank::factory()->forDepartment($dev)->create();
    HourBank::factory()->forDepartment($dev)->closed()->create();

    $this->actingAs($this->admin)
        ->get('/admin/departamentos')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/departments/index')
            ->has('departments', 2)
            ->where('departments.0.name', 'Desarrollo')
            ->where('departments.0.open_hour_banks_count', 1)
            ->where('departments.0.can_delete', false)
            ->where('departments.1.name', 'Diseño')
            ->where('departments.1.users_count', 2)
            ->has('departments.1.managers', 2)
            ->where('departments.1.managers.0.name', 'Bea')
            ->where('palette', ['#0171FF', '#179FA5', '#5E2DAD', '#E65FB3', '#3C41AE', '#0892C4', '#56667A'])
            ->has('managerOptions', 3));
});

test('crea un departamento con varios responsables', function () {
    $manager = userWithRole('department_manager');
    $otherAdmin = userWithRole('admin');

    $this->actingAs($this->admin)
        ->post('/admin/departamentos', [
            'name' => ' Producción ',
            'color' => '#5e2dad',
            'manager_ids' => [$manager->id, $otherAdmin->id],
        ])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.type', 'success');

    $department = Department::query()->where('name', 'Producción')->sole();
    expect($department->color)->toBe('#5E2DAD')
        ->and($department->managers()->pluck('users.id')->sort()->values()->all())->toBe(collect([$manager->id, $otherAdmin->id])->sort()->values()->all());
});

test('los responsables tienen que ser internos activos con rol de responsable o admin', function (Closure $candidate) {
    $user = $candidate->call($this);

    $this->actingAs($this->admin)
        ->post('/admin/departamentos', ['name' => 'Producción', 'color' => '#0171FF', 'manager_ids' => [$user->id]])
        ->assertSessionHasErrors('manager_ids');

    expect(Department::query()->count())->toBe(0);
})->with([
    'empleado' => [fn () => userWithRole('employee')],
    'responsable desactivado' => [fn () => userWithRole('department_manager', ['is_active' => false])],
    'cliente' => [fn () => userWithRole('client')],
]);

test('el nombre es único sin distinguir mayúsculas y el color es de la paleta', function () {
    Department::factory()->create(['name' => 'Diseño']);

    $this->actingAs($this->admin)
        ->post('/admin/departamentos', ['name' => 'DISEÑO', 'color' => '#123456'])
        ->assertSessionHasErrors(['color']);

    $this->actingAs($this->admin)
        ->post('/admin/departamentos', ['name' => 'diseño', 'color' => '#0171FF'])
        ->assertSessionHasErrors(['name']);

    expect(Department::query()->count())->toBe(1);
});

test('edita el nombre, el color y cambia los responsables', function () {
    $department = Department::factory()->create(['name' => 'Diseño', 'color' => '#000000']);
    $old = userWithRole('department_manager');
    $new = userWithRole('department_manager');
    $department->managers()->attach($old);

    // Conserva un color antiguo que no está en la paleta si no se cambia.
    $this->actingAs($this->admin)
        ->put("/admin/departamentos/{$department->id}", ['name' => 'Diseño y UX', 'color' => '#000000', 'manager_ids' => [$new->id]])
        ->assertSessionHasNoErrors();

    $department->refresh();
    expect($department->name)->toBe('Diseño y UX')
        ->and($department->color)->toBe('#000000')
        ->and($department->managers()->pluck('users.id')->all())->toBe([$new->id]);

    // Y se le puede quitar a todos los responsables.
    $this->actingAs($this->admin)
        ->put("/admin/departamentos/{$department->id}", ['name' => 'Diseño y UX', 'color' => '#179FA5'])
        ->assertSessionHasNoErrors();

    expect($department->managers()->count())->toBe(0)->and($department->fresh()?->color)->toBe('#179FA5');
});

test('no se borra con personas activas ni con bolsas abiertas', function () {
    $department = Department::factory()->create();
    $active = User::factory()->employee()->inDepartment($department)->create();

    $this->actingAs($this->admin)
        ->delete("/admin/departamentos/{$department->id}")
        ->assertSessionHasErrors('department');

    $active->update(['department_id' => null]);
    $bank = HourBank::factory()->forDepartment($department)->create();

    $this->actingAs($this->admin)
        ->delete("/admin/departamentos/{$department->id}")
        ->assertSessionHasErrors('department');

    expect(Department::query()->whereKey($department->id)->exists())->toBeTrue();

    $bank->update(['status' => 'closed', 'closed_at' => now()]);

    $this->actingAs($this->admin)
        ->delete("/admin/departamentos/{$department->id}")
        ->assertSessionHasNoErrors();

    expect(Department::query()->whereKey($department->id)->exists())->toBeFalse()
        ->and(Department::withTrashed()->whereKey($department->id)->exists())->toBeTrue()
        ->and($bank->fresh()?->department?->id)->toBe($department->id);
});

test('al borrarlo, las personas desactivadas, los responsables y los tipos de tarea se desvinculan', function () {
    $department = Department::factory()->create();
    $former = User::factory()->employee()->inDepartment($department)->inactive()->create();
    $manager = userWithRole('department_manager');
    $department->managers()->attach($manager);
    $type = TaskType::factory()->create(['department_id' => $department->id]);

    $this->actingAs($this->admin)
        ->delete("/admin/departamentos/{$department->id}")
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.type', 'success');

    expect($former->fresh()?->department_id)->toBeNull()
        ->and($type->fresh()?->department_id)->toBeNull()
        ->and($manager->managedDepartments()->count())->toBe(0);
});
