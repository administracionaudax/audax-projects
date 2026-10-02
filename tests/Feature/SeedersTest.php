<?php

use App\Domain\Time\Capacity;
use App\Enums\AbsenceStatus;
use App\Enums\AbsenceType;
use App\Enums\Role;
use App\Models\Absence;
use App\Models\Client;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\TimesheetPeriod;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DefaultSettingsSeeder;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

test('el seeder de desarrollo crea las cuentas de los E2E, el responsable de Diseño y los datos de ejemplo', function () {
    $this->seed(DatabaseSeeder::class);

    expect(User::role(Role::Admin->value)->count())->toBe(1)
        ->and(User::role(Role::DepartmentManager->value)->count())->toBe(3)
        ->and(User::role(Role::Employee->value)->count())->toBe(6)
        ->and(User::role(Role::Client->value)->count())->toBe(2);

    foreach (['admin@example.com', 'responsable@example.com', 'empleado@example.com', 'cliente@example.com', 'cliente.lamas@example.com'] as $email) {
        expect(User::query()->where('email', $email)->exists())->toBeTrue();
    }

    // La persona del portal de un cliente sin bolsas (E2E brand-gradient: estado vacío grande).
    $withoutBanks = User::query()->where('email', 'cliente.lamas@example.com')->sole();
    expect($withoutBanks->client_id)->not->toBeNull()
        ->and($withoutBanks->hasRole(Role::Client->value))->toBeTrue()
        ->and(HourBank::query()->whereHas('project', fn ($query) => $query->where('client_id', $withoutBanks->client_id))->exists())->toBeFalse();

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

test('los datos de ejemplo tienen festivos y ausencias, y nadie imputa en un día sin capacidad (SPEC §9 y §15)', function () {
    $this->seed(DatabaseSeeder::class);
    $today = LocalTime::todayString();
    $email = fn (string $address): int => User::query()->where('email', $address)->value('id');

    // Festivos nacionales del año pasado, este y el que viene.
    expect(Holiday::query()->count())->toBe(30);

    // Ausencias pasadas y aprobadas dentro de los 12 meses de horas: dos semanas de vacaciones,
    // un día de formación, una baja de dos días y medio día.
    $past = Absence::query()->with('user')->approved()->where('end_date', '<', $today)->get();
    expect($past->map(fn (Absence $absence): string => $absence->type->value.':'.($absence->start_date->diffInWeekdays($absence->end_date) + 1).':'.($absence->partial_minutes ?? 'dia'))->sort()->values()->all())
        ->toBe(['leave:1:240', 'sick:2:dia', 'training:1:dia', 'vacation:5:dia', 'vacation:5:dia'])
        ->and($past->every(fn (Absence $absence): bool => $absence->approved_by !== null && $absence->reviewed_at < $absence->start_date))->toBeTrue();

    // Las futuras de los E2E: Elena de vacaciones la semana que viene y una solicitud de Lucía.
    $nextMonday = CarbonImmutable::parse($today)->startOfWeek()->addWeek();
    expect(Absence::query()->approved()->where('user_id', $email('empleado@example.com'))->where('type', AbsenceType::Vacation->value)
        ->whereBetween('start_date', [$nextMonday->toDateString(), $nextMonday->addDays(6)->toDateString()])->exists())->toBeTrue()
        ->and(Absence::query()->where('status', AbsenceStatus::Requested->value)->where('user_id', $email('lucia.martin@example.com'))->exists())->toBeTrue();

    // El día sobrecargado de Lucía para el E2E de Carga: 16 h en el primer día laborable de la
    // semana que viene, sin horas imputadas.
    $overload = Task::query()->where('title', 'Maquetas para la feria de turismo')->sole();
    expect($overload->assignee_user_id)->toBe($email('lucia.martin@example.com'))
        ->and($overload->estimated_minutes)->toBe(960)
        ->and($overload->start_date?->toDateString())->toBe($overload->due_date?->toDateString())
        ->and($overload->due_date?->toDateString() >= $nextMonday->toDateString())->toBeTrue()
        ->and($overload->due_date?->isWeekend())->toBeFalse()
        ->and(Holiday::query()->whereDate('date', (string) $overload->due_date?->toDateString())->exists())->toBeFalse()
        ->and(TimeEntry::query()->where('task_id', $overload->id)->exists())->toBeFalse();

    // Nadie imputa en un festivo ni en un día de ausencia completa; con medio día, no más de lo que queda.
    expect(TimeEntry::query()->whereIn('date', Holiday::query()->pluck('date')->map(fn ($date): string => $date->toDateString()))->count())->toBe(0);

    foreach ($past as $absence) {
        $logged = TimeEntry::query()->where('user_id', $absence->user_id)
            ->whereBetween('date', [$absence->start_date->toDateString(), $absence->end_date->toDateString()]);

        if ($absence->partial_minutes === null) {
            expect($logged->count())->toBe(0);
        } else {
            $capacity = app(Capacity::class)->onDate($absence->user, $absence->start_date);
            expect($capacity)->toBeGreaterThan(0)
                ->and((int) $logged->sum('minutes'))->toBeLessThanOrEqual($capacity);
        }
    }
});

test('el seeder de desarrollo es repetible', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(User::query()->count())->toBe(12)
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
