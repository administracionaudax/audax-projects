<?php

use App\Enums\Role;
use App\Models\Department;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DefaultSettingsSeeder;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

test('el seeder de desarrollo crea un usuario por rol y el responsable de Diseño', function () {
    $this->seed(DatabaseSeeder::class);

    foreach (Role::cases() as $role) {
        expect(User::role($role->value)->count())->toBe(1);
    }

    $manager = User::role(Role::DepartmentManager->value)->sole();

    expect(Department::query()->where('name', 'Diseño')->value('manager_user_id'))->toBe($manager->id)
        ->and(Setting::query()->count())->toBe(count(Setting::DEFAULTS));
});

test('el seeder de desarrollo es repetible', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(User::query()->count())->toBe(4)->and(Department::query()->count())->toBe(3);
});

test('el seeder de desarrollo se niega a ejecutarse en producción', function () {
    app()['env'] = 'production';

    expect(fn () => (new DatabaseSeeder)->run())->toThrow(RuntimeException::class);
    expect(User::query()->count())->toBe(0);
});

test('los seeders base son idempotentes y no pisan cambios', function () {
    $this->seed([RolesAndPermissionsSeeder::class, DefaultSettingsSeeder::class, DepartmentsSeeder::class]);

    Setting::set('max_attachment_mb', 20);
    Department::query()->where('name', 'Marketing')->update(['color' => '#000000']);

    $this->seed([RolesAndPermissionsSeeder::class, DefaultSettingsSeeder::class, DepartmentsSeeder::class]);

    expect(Setting::get('max_attachment_mb'))->toBe(20)
        ->and(Department::query()->where('name', 'Marketing')->value('color'))->toBe('#000000')
        ->and(Department::query()->count())->toBe(3);
});

test('un departamento borrado no se vuelve a crear', function () {
    $this->seed(DepartmentsSeeder::class);

    Department::query()->where('name', 'Marketing')->firstOrFail()->delete();

    $this->seed(DepartmentsSeeder::class);

    expect(Department::query()->count())->toBe(2)
        ->and(Department::withTrashed()->count())->toBe(3);
});

test('las relaciones de departamento funcionan', function () {
    $department = Department::factory()->create();
    $manager = userWithRole('department_manager', ['department_id' => $department->id]);
    $department->update(['manager_user_id' => $manager->id]);
    User::factory()->employee()->inDepartment($department)->create();

    expect($department->fresh()?->users)->toHaveCount(2)
        ->and($department->fresh()?->manager?->id)->toBe($manager->id)
        ->and($manager->department?->id)->toBe($department->id);
});

test('las etiquetas de los roles están en español', function () {
    expect(Role::Admin->label())->toBe('Administración')
        ->and(Role::DepartmentManager->label())->toBe('Responsable de departamento')
        ->and(Role::Employee->label())->toBe('Empleado')
        ->and(Role::Client->label())->toBe('Cliente');
});

test('el scope active y el scope internal filtran usuarios', function () {
    userWithRole('employee');
    userWithRole('employee', ['is_active' => false]);
    userWithRole('client');

    expect(User::query()->active()->count())->toBe(2)
        ->and(User::query()->internal()->count())->toBe(2)
        ->and(User::query()->active()->internal()->count())->toBe(1);
});
