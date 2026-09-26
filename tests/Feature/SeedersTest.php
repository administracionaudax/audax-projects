<?php

use App\Enums\Role;
use App\Models\Client;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Setting;
use App\Models\TimeEntry;
use App\Models\TimesheetPeriod;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DefaultSettingsSeeder;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

test('el seeder de desarrollo crea las cuentas de los E2E, el responsable de Diseño y los datos de ejemplo', function () {
    $this->seed(DatabaseSeeder::class);

    expect(User::role(Role::Admin->value)->count())->toBe(1)
        ->and(User::role(Role::DepartmentManager->value)->count())->toBe(3)
        ->and(User::role(Role::Employee->value)->count())->toBe(6)
        ->and(User::role(Role::Client->value)->count())->toBe(1);

    foreach (['admin@example.com', 'responsable@example.com', 'empleado@example.com', 'cliente@example.com'] as $email) {
        expect(User::query()->where('email', $email)->exists())->toBeTrue();
    }

    $manager = User::query()->where('email', 'responsable@example.com')->sole();

    expect(Department::query()->where('name', 'Diseño')->sole()->managers->pluck('id')->all())->toBe([$manager->id])
        ->and(Setting::query()->count())->toBe(count(Setting::DEFAULTS));
});

test('los datos de ejemplo cubren el SPEC §15 y son coherentes con el motor de bolsas', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Client::query()->count())->toBe(8)
        ->and(Project::query()->count())->toBe(15)
        ->and(HourBank::query()->pluck('status')->map->value->unique()->sort()->values()->all())
        ->toBe(['active', 'closed', 'exhausted', 'renewed']);

    // 12 meses de horas.
    expect(TimeEntry::query()->min('date'))->toBeLessThanOrEqual(now()->subMonths(11)->toDateString())
        ->and(TimeEntry::query()->count())->toBeGreaterThan(3000);

    // La caché de cada bolsa coincide con sus entradas (D-019): consumo y exceso.
    HourBank::query()->each(function (HourBank $bank): void {
        $entries = TimeEntry::query()->where('hour_bank_id', $bank->id);
        expect($bank->consumed_minutes)->toBe((int) $entries->sum('minutes'))
            ->and($bank->overage_minutes)->toBe((int) (clone $entries)->sum('overage_minutes'))
            ->and($bank->overage_minutes)->toBe(max($bank->consumed_minutes - $bank->total_minutes, 0));
    });

    // Hay exceso en alguna bolsa y ninguno en la de política block.
    expect(HourBank::query()->where('overage_minutes', '>', 0)->exists())->toBeTrue()
        ->and(HourBank::query()->where('overage_policy', 'block')->sum('overage_minutes'))->toBe(0);

    // Flujo de aprobación: aprobadas con instantáneas, enviadas, una semana devuelta y bloqueadas al facturar.
    expect(TimeEntry::query()->where('status', 'approved')->whereNull('hourly_cost_snapshot')->count())->toBe(0)
        ->and(TimeEntry::query()->where('status', 'submitted')->exists())->toBeTrue()
        ->and(TimeEntry::query()->where('status', 'locked')->exists())->toBeTrue()
        ->and(TimesheetPeriod::query()->where('status', 'returned')->count())->toBe(1);

    // Nadie supera 24 h al día ni imputa en bolsas de otro departamento.
    expect(TimeEntry::query()->selectRaw('user_id, date, SUM(minutes) as total')->groupBy('user_id', 'date')->havingRaw('SUM(minutes) > 1440')->count())->toBe(0);
    $foreign = TimeEntry::query()
        ->join('hour_banks', 'hour_banks.id', '=', 'time_entries.hour_bank_id')
        ->join('users', 'users.id', '=', 'time_entries.user_id')
        ->whereNotNull('hour_banks.department_id')
        ->whereColumn('hour_banks.department_id', '!=', 'users.department_id')
        ->count();
    expect($foreign)->toBe(0);
});

test('el seeder de desarrollo es repetible', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(User::query()->count())->toBe(11)
        ->and(Department::query()->count())->toBe(3)
        ->and(Project::query()->count())->toBe(15);
});

test('el seeder de desarrollo se niega a ejecutarse fuera de local y testing', function (string $env) {
    app()['env'] = $env;

    expect(fn () => (new DatabaseSeeder)->run())->toThrow(RuntimeException::class);
    expect(User::query()->count())->toBe(0);
})->with(['production', 'staging']);

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

test('un departamento puede tener varios responsables (D-024)', function () {
    $department = Department::factory()->create();
    $manager = userWithRole('department_manager', ['department_id' => $department->id]);
    $coManager = userWithRole('department_manager', ['department_id' => $department->id]);
    $department->managers()->attach([$manager->id, $coManager->id]);
    User::factory()->employee()->inDepartment($department)->create();

    expect($department->fresh()?->users)->toHaveCount(3)
        ->and($department->fresh()?->managers->pluck('id')->sort()->values()->all())->toBe([$manager->id, $coManager->id])
        ->and($manager->managesDepartment($department))->toBeTrue()
        ->and(User::factory()->employee()->create()->managesDepartment($department))->toBeFalse()
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
