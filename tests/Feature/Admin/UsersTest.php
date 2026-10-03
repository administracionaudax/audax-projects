<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Department;
use App\Models\LoginEvent;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Notifications\Admin\UserInvitation;
use App\Support\LocalTime;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Usuarios (SPEC §14): listado, alta por invitación, edición y reglas de los admins.
*/

beforeEach(function () {
    $this->admin = userWithRole('admin', ['name' => 'Ana Admin', 'email' => 'ana.admin@audaxstudio.com']);
});

describe('listado', function () {
    test('lista solo a las personas internas, con su rol, departamento y último acceso, sin N+1', function () {
        $design = Department::factory()->create(['name' => 'Diseño']);
        $laura = userWithRole('employee', ['name' => 'Laura Gómez', 'department_id' => $design->id]);
        userWithRole('department_manager', ['name' => 'Marc Puig', 'department_id' => $design->id]);
        userWithRole('employee', ['name' => 'Nerea Ibáñez']);
        userWithRole('client', ['name' => 'Carlos Cliente']);
        LoginEvent::query()->create(['user_id' => $laura->id, 'email' => $laura->email, 'succeeded' => true]);

        $this->actingAs($this->admin)
            ->get('/admin/usuarios')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/users/index')
                ->has('users.data', 4)
                ->where('users.data.0.name', 'Ana Admin')
                ->where('users.data.0.role', 'admin')
                ->where('users.data.1.name', 'Laura Gómez')
                ->where('users.data.1.department.name', 'Diseño')
                ->where('users.data.1.role', 'employee')
                ->whereNot('users.data.1.last_login_at', null)
                ->where('users.data.2.role', 'department_manager')
                ->where('users.data.3.last_login_at', null)
                ->where('users.meta.total', 4)
                ->where('filters.estado', 'activos')
                ->has('departments', 1)
                ->where('roles', ['admin', 'department_manager', 'employee', 'collaborator'])
                ->where('canGrantAdmin', true));
    });

    test('busca por nombre o correo y filtra por rol, departamento y estado', function () {
        $design = Department::factory()->create();
        userWithRole('employee', ['name' => 'Laura Gómez', 'email' => 'laura@audaxstudio.com', 'department_id' => $design->id]);
        userWithRole('employee', ['name' => 'Pedro Ruiz', 'email' => 'pruiz@audaxstudio.com']);
        userWithRole('department_manager', ['name' => 'Marta Sanz', 'department_id' => $design->id]);
        userWithRole('employee', ['name' => 'Luis Baja', 'is_active' => false]);

        $names = fn (string $query) => collect($this->actingAs($this->admin)->get('/admin/usuarios'.$query)
            ->assertOk()
            ->inertiaProps('users.data'))->pluck('name')->all();

        expect($names('?q=laura'))->toBe(['Laura Gómez'])
            ->and($names('?q=PRUIZ@'))->toBe(['Pedro Ruiz'])
            ->and($names('?rol=department_manager'))->toBe(['Marta Sanz'])
            ->and($names("?departamento={$design->id}"))->toBe(['Laura Gómez', 'Marta Sanz'])
            ->and($names('?departamento=ninguno'))->toBe(['Ana Admin', 'Pedro Ruiz'])
            ->and($names('?estado=inactivos'))->toBe(['Luis Baja'])
            ->and($names('?estado=todos'))->toHaveCount(5)
            ->and($names('?q=nadie'))->toBe([]);
    });

    test('rechaza filtros no válidos', function () {
        $this->actingAs($this->admin)
            ->get('/admin/usuarios?rol=client&estado=raro')
            ->assertSessionHasErrors(['rol', 'estado']);
    });

    test('pagina de 25 en 25', function () {
        User::factory()->employee()->count(30)->create();

        $this->actingAs($this->admin)
            ->get('/admin/usuarios?page=2')
            ->assertInertia(fn (Assert $page) => $page
                ->has('users.data', 6)
                ->where('users.meta.current_page', 2)
                ->where('users.meta.last_page', 2)
                ->where('users.meta.total', 31));
    });

    test('los datos económicos solo llegan con view-financials', function () {
        userWithRole('employee', ['name' => 'Zoe', 'hourly_cost' => '25.50', 'default_hourly_rate' => '60.00']);

        $this->actingAs($this->admin)
            ->get('/admin/usuarios?q=zoe')
            ->assertInertia(fn (Assert $page) => $page
                ->where('users.data.0.hourly_cost', '25.50')
                ->where('users.data.0.default_hourly_rate', '60.00'));

        $manager = userWithRole('department_manager');
        $manager->givePermissionTo(Permission::ManageUsers->value);

        $this->actingAs($manager)
            ->get('/admin/usuarios?q=zoe')
            ->assertInertia(fn (Assert $page) => $page
                ->has('users.data', 1)
                ->missing('users.data.0.hourly_cost')
                ->missing('users.data.0.default_hourly_rate')
                ->where('canGrantAdmin', false));
    });
});

describe('alta por invitación', function () {
    beforeEach(function () {
        Notification::fake();
        $this->travelTo(LocalTime::today()->setTime(10, 0));
    });

    test('crea la persona con una contraseña que nadie conoce, su jornada por defecto y le envía la invitación por la cola mail', function () {
        Setting::set('default_work_minutes', [420, 420, 420, 420, 360, 0, 0]);
        Setting::set('company_name', 'Audax Studio');
        $design = Department::factory()->create();

        $this->actingAs($this->admin)
            ->post('/admin/usuarios', [
                'name' => '  Laura   Gómez ',
                'email' => ' Laura@AudaxStudio.com ',
                'role' => 'employee',
                'department_id' => $design->id,
                'hourly_cost' => '25,50',
                'default_hourly_rate' => '60',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $user = User::query()->where('email', 'laura@audaxstudio.com')->sole();

        expect($user->name)->toBe('Laura Gómez')
            ->and($user->hasRole(Role::Employee->value))->toBeTrue()
            ->and($user->department_id)->toBe($design->id)
            ->and($user->hourly_cost)->toBe('25.50')
            ->and($user->default_hourly_rate)->toBe('60.00')
            ->and($user->is_active)->toBeTrue()
            ->and(Hash::check('password', $user->password))->toBeFalse();

        $schedule = WorkSchedule::query()->where('user_id', $user->id)->sole();
        expect($schedule->valid_from->toDateString())->toBe(LocalTime::todayString())
            ->and($schedule->valid_to)->toBeNull()
            ->and($schedule->weekMinutes())->toBe([420, 420, 420, 420, 360, 0, 0]);

        Notification::assertSentTo($user, UserInvitation::class, function (UserInvitation $notification, array $channels) use ($user) {
            $mail = $notification->toMail($user);

            return $channels === ['mail']
                && $notification->queue === 'mail'
                && $notification->companyName === 'Audax Studio'
                && $notification->invitedBy === 'Ana Admin'
                && str_contains((string) $mail->subject, 'Audax Studio')
                && str_contains((string) $mail->actionUrl, '/invitacion/')
                && str_contains(implode(' ', $mail->outroLines), '7 días')
                && Password::broker('invitations')->tokenExists($user, $notification->token);
        });
    });

    test('el enlace de la invitación sirve para fijar la contraseña y entrar', function () {
        $this->actingAs($this->admin)->post('/admin/usuarios', [
            'name' => 'Laura', 'email' => 'laura@audaxstudio.com', 'role' => 'employee',
        ]);
        auth()->logout();

        $user = User::query()->where('email', 'laura@audaxstudio.com')->sole();
        $token = null;
        Notification::assertSentTo($user, UserInvitation::class, function (UserInvitation $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        // El enlace sigue valiendo días después (broker de invitaciones, 7 días).
        $this->travel(3)->days();
        $this->post(route('invitation.store'), [
            'token' => $token,
            'email' => 'laura@audaxstudio.com',
            'password' => 'una-contraseña-larga',
            'password_confirmation' => 'una-contraseña-larga',
        ])->assertSessionHasNoErrors();

        $this->post(route('login.store'), ['email' => 'laura@audaxstudio.com', 'password' => 'una-contraseña-larga']);
        $this->assertAuthenticatedAs($user);
    });

    test('valida los datos: correo único sin distinguir mayúsculas, rol interno y departamento existente', function () {
        userWithRole('employee', ['email' => 'laura@audaxstudio.com']);
        $deleted = Department::factory()->create();
        $deleted->delete();

        $this->actingAs($this->admin)
            ->post('/admin/usuarios', [
                'name' => '',
                'email' => 'LAURA@audaxstudio.com',
                'role' => 'client',
                'department_id' => $deleted->id,
            ])
            ->assertSessionHasErrors(['name', 'email', 'role', 'department_id']);

        expect(User::query()->count())->toBe(2);
        Notification::assertNothingSent();
    });

    test('sin view-financials se ignoran el coste y la tarifa, y no se puede dar el rol de admin', function () {
        $manager = userWithRole('department_manager');
        $manager->givePermissionTo(Permission::ManageUsers->value);

        $this->actingAs($manager)
            ->post('/admin/usuarios', [
                'name' => 'Pedro', 'email' => 'pedro@audaxstudio.com', 'role' => 'employee',
                'hourly_cost' => '99', 'default_hourly_rate' => '99',
            ])
            ->assertSessionHasNoErrors();

        $pedro = User::query()->where('email', 'pedro@audaxstudio.com')->sole();
        expect($pedro->hourly_cost)->toBeNull()->and($pedro->default_hourly_rate)->toBeNull();

        $this->actingAs($manager)
            ->post('/admin/usuarios', ['name' => 'Eva', 'email' => 'eva@audaxstudio.com', 'role' => 'admin'])
            ->assertForbidden();

        expect(User::query()->where('email', 'eva@audaxstudio.com')->exists())->toBeFalse();
    });

    test('«Reenviar invitación» emite un enlace nuevo; a una persona desactivada no', function () {
        $user = userWithRole('employee');

        $this->actingAs($this->admin)
            ->post("/admin/usuarios/{$user->id}/invitacion")
            ->assertRedirect()
            ->assertInertiaFlash('toast.type', 'success');

        Notification::assertSentToTimes($user, UserInvitation::class, 1);

        $inactive = userWithRole('employee', ['is_active' => false]);

        $this->actingAs($this->admin)
            ->post("/admin/usuarios/{$inactive->id}/invitacion")
            ->assertInertiaFlash('toast.type', 'error');

        Notification::assertNotSentTo($inactive, UserInvitation::class);
    });
});

describe('ficha y edición', function () {
    test('la ficha trae los datos, la jornada y lo que puede hacer quien la mira', function () {
        $user = userWithRole('employee');
        WorkSchedule::factory()->create(['user_id' => $user->id, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-30']);
        WorkSchedule::factory()->intensive()->create(['user_id' => $user->id, 'valid_from' => '2026-07-01']);

        $this->actingAs($this->admin)
            ->get("/admin/usuarios/{$user->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/users/edit')
                ->where('user.id', $user->id)
                ->has('schedules', 2)
                ->where('schedules.0.valid_from', '2026-07-01')
                ->where('schedules.0.week', [420, 420, 420, 420, 360, 0, 0])
                ->where('schedules.0.weekly_minutes', 2040)
                ->where('schedules.1.valid_to', '2026-06-30')
                ->where('schedules.1.is_editable', false)
                ->where('can.manage', true)
                ->where('can.deactivate', true)
                ->where('openTasksCount', 0)
                ->where('hasActiveTimer', false));
    });

    test('los usuarios del portal no se gestionan aquí', function () {
        $client = userWithRole('client');

        $this->actingAs($this->admin)->get("/admin/usuarios/{$client->id}")->assertNotFound();
        $this->actingAs($this->admin)
            ->put("/admin/usuarios/{$client->id}", ['name' => 'X', 'email' => $client->email, 'role' => 'employee'])
            ->assertForbidden();

        expect($client->fresh()?->isClient())->toBeTrue();
    });

    test('edita nombre, correo, rol y departamento', function () {
        $design = Department::factory()->create();
        $user = userWithRole('employee', ['email' => 'laura@audaxstudio.com']);

        $this->actingAs($this->admin)
            ->put("/admin/usuarios/{$user->id}", [
                'name' => 'Laura Gómez',
                'email' => 'Laura.Gomez@AudaxStudio.com',
                'role' => 'department_manager',
                'department_id' => $design->id,
            ])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        $user->refresh();
        expect($user->name)->toBe('Laura Gómez')
            ->and($user->email)->toBe('laura.gomez@audaxstudio.com')
            ->and($user->getRoleNames()->all())->toBe(['department_manager'])
            ->and($user->department_id)->toBe($design->id);
    });

    test('el correo sigue siendo único al editar', function () {
        userWithRole('employee', ['email' => 'ocupado@audaxstudio.com']);
        $user = userWithRole('employee');

        $this->actingAs($this->admin)
            ->put("/admin/usuarios/{$user->id}", ['name' => 'X', 'email' => 'OCUPADO@audaxstudio.com', 'role' => 'employee'])
            ->assertSessionHasErrors('email');
    });

    test('pasar a empleado quita a la persona de los responsables de sus departamentos', function () {
        $department = Department::factory()->create();
        $manager = userWithRole('department_manager');
        $department->managers()->attach($manager);

        $this->actingAs($this->admin)
            ->put("/admin/usuarios/{$manager->id}", ['name' => $manager->name, 'email' => $manager->email, 'role' => 'employee'])
            ->assertSessionHasNoErrors();

        expect($department->managers()->count())->toBe(0);
    });

    test('un admin no puede quitarse su propio rol de admin', function () {
        userWithRole('admin');

        $this->actingAs($this->admin)
            ->put("/admin/usuarios/{$this->admin->id}", ['name' => 'Ana', 'email' => $this->admin->email, 'role' => 'employee'])
            ->assertSessionHasErrors('role');

        expect($this->admin->fresh()?->isAdmin())->toBeTrue();
    });

    test('no se puede degradar al último admin activo', function () {
        $other = userWithRole('admin', ['is_active' => false]);
        $second = userWithRole('admin');

        // Con dos admins activos, uno puede degradar al otro…
        $this->actingAs($this->admin)
            ->put("/admin/usuarios/{$second->id}", ['name' => 'B', 'email' => $second->email, 'role' => 'employee'])
            ->assertSessionHasNoErrors();

        // …pero no al último activo (el desactivado no cuenta).
        $this->actingAs($this->admin)
            ->put("/admin/usuarios/{$other->id}", ['name' => 'C', 'email' => $other->email, 'role' => 'employee'])
            ->assertSessionHasNoErrors();

        expect(User::role(Role::Admin->value)->where('is_active', true)->count())->toBe(1);

        $this->actingAs($this->admin)
            ->get("/admin/usuarios/{$this->admin->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.changeRole', false)
                ->where('can.deactivate', false));
    });

    test('quien gestiona usuarios sin ser admin no puede tocar a un admin', function () {
        $manager = userWithRole('department_manager');
        $manager->givePermissionTo(Permission::ManageUsers->value);

        $this->actingAs($manager)
            ->put("/admin/usuarios/{$this->admin->id}", ['name' => 'Hackeado', 'email' => $this->admin->email, 'role' => 'admin'])
            ->assertForbidden();

        $employee = userWithRole('employee');
        $this->actingAs($manager)
            ->put("/admin/usuarios/{$employee->id}", ['name' => 'Ascendido', 'email' => $employee->email, 'role' => 'admin'])
            ->assertSessionHasErrors('role');

        expect($this->admin->fresh()?->name)->toBe('Ana Admin')
            ->and($employee->fresh()?->isAdmin())->toBeFalse();

        $this->actingAs($manager)
            ->get("/admin/usuarios/{$this->admin->id}")
            ->assertInertia(fn (Assert $page) => $page->where('can.manage', false));
    });
});
