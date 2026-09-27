<?php

use App\Http\Controllers\Auth\InvitationController;
use App\Models\Client;
use App\Models\LoginEvent;
use App\Models\Project;
use App\Models\User;
use App\Notifications\Admin\UserInvitation;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Usuarios del portal desde la ficha del cliente (SPEC §11, D-063): invitar (enlace de 7 días por la
| cola mail), reenviar, revocar (desactiva y cierra sus sesiones al momento; queda en la auditoría) y
| reactivar. Pueden hacerlo el admin, los responsables y los gestores de algún proyecto del cliente.
*/

beforeEach(function () {
    $this->client = Client::factory()->create(['name' => 'Bodegas Lur']);
    $this->other = Client::factory()->create(['name' => 'Hoteles Mirador']);
    $this->admin = userWithRole('admin', ['name' => 'Ana Admin']);

    // Gestor de un proyecto de ESTE cliente y gestor de un proyecto de OTRO cliente.
    $this->manager = userWithRole('employee', ['name' => 'Gema Gestora']);
    Project::factory()->create(['client_id' => $this->client->id, 'owner_user_id' => $this->manager->id]);
    $this->foreignManager = userWithRole('employee');
    Project::factory()->create(['client_id' => $this->other->id, 'owner_user_id' => $this->foreignManager->id]);

    $this->portalUser = User::factory()->portalOf($this->client)->create(['name' => 'Íñigo Lur', 'email' => 'inigo@lur.example']);
    $this->invite = fn (array $data = []) => $this->post("/clientes/{$this->client->id}/portal/usuarios", [
        'name' => 'Carmen Lur',
        'email' => 'Carmen@Lur.example ',
        ...$data,
    ]);
});

test('el admin invita: crea un usuario cliente de ese cliente y le envía por la cola mail un enlace de 7 días', function () {
    Notification::fake();

    $this->actingAs($this->admin);
    ($this->invite)()
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', __('portal.access.invited', ['email' => 'carmen@lur.example']));

    $user = User::query()->where('email', 'carmen@lur.example')->sole();
    expect($user->client_id)->toBe($this->client->id)
        ->and($user->isClient())->toBeTrue()
        ->and($user->is_active)->toBeTrue()
        ->and($user->department_id)->toBeNull()
        ->and($user->workSchedules()->exists())->toBeFalse()
        ->and(DB::table('invitation_tokens')->where('email', 'carmen@lur.example')->exists())->toBeTrue();

    Notification::assertSentTo($user, UserInvitation::class, function (UserInvitation $notification) use ($user) {
        $mail = $notification->toMail($user);

        return $notification->queue === 'mail'
            && $notification->invitedBy === 'Ana Admin'
            && str_contains((string) $mail->actionUrl, '/invitacion/')
            && str_contains((string) $mail->subject, 'portal de clientes')
            && collect($mail->outroLines)->contains(fn ($line) => str_contains((string) $line, '7 días'))
            && collect($mail->introLines)->contains(fn ($line) => str_contains((string) $line, 'portal de clientes'));
    });

    $this->assertDatabaseHas('activity_log', [
        'log_name' => 'clients',
        'subject_id' => $this->client->id,
        'event' => 'portal_user_invited',
        'causer_id' => $this->admin->id,
    ]);
});

test('la invitación va a la cola mail y el enlace vale 7 días (y ni uno más)', function () {
    Queue::fake();

    $this->actingAs($this->admin);
    ($this->invite)()->assertSessionHasNoErrors();

    Queue::assertPushedOn('mail', SendQueuedNotifications::class);

    $user = User::query()->where('email', 'carmen@lur.example')->sole();
    $token = Password::broker(InvitationController::BROKER)->createToken($user);

    $this->travel(6)->days();
    expect(Password::broker(InvitationController::BROKER)->tokenExists($user, $token))->toBeTrue();

    $this->travel(2)->days();
    expect(Password::broker(InvitationController::BROKER)->tokenExists($user, $token))->toBeFalse();
});

test('el correo tiene que ser único: ni del equipo, ni de otro cliente, ni repetido', function () {
    Notification::fake();
    userWithRole('employee', ['email' => 'elena@audax.example']);
    User::factory()->portalOf($this->other)->create(['email' => 'jorge@mirador.example']);

    $this->actingAs($this->admin);

    ($this->invite)(['email' => 'ELENA@audax.example'])->assertSessionHasErrors(['email' => __('portal.access.errors.email_internal')]);
    ($this->invite)(['email' => 'jorge@mirador.example'])->assertSessionHasErrors(['email' => __('portal.access.errors.email_taken')]);
    ($this->invite)(['email' => 'inigo@lur.example'])->assertSessionHasErrors(['email' => __('portal.access.errors.email_same_client')]);
    ($this->invite)(['email' => 'no-es-un-correo'])->assertSessionHasErrors('email');
    ($this->invite)(['name' => ''])->assertSessionHasErrors('name');

    expect(User::query()->where('client_id', $this->client->id)->count())->toBe(1);
    Notification::assertNothingSent();
});

test('no se invita a nadie de un cliente desactivado', function () {
    $this->client->update(['is_active' => false]);

    $this->actingAs($this->admin);
    ($this->invite)()->assertSessionHasErrors(['client' => __('portal.access.errors.client_inactive')]);

    expect(User::query()->where('email', 'carmen@lur.example')->exists())->toBeFalse();
});

test('reenviar genera un enlace nuevo que invalida el anterior', function () {
    Notification::fake();
    $old = Password::broker(InvitationController::BROKER)->createToken($this->portalUser);

    $this->actingAs($this->manager)
        ->post("/clientes/{$this->client->id}/portal/usuarios/{$this->portalUser->id}/invitacion")
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    Notification::assertSentTo($this->portalUser, UserInvitation::class);
    expect(Password::broker(InvitationController::BROKER)->tokenExists($this->portalUser, $old))->toBeFalse();
    $this->assertDatabaseHas('activity_log', ['subject_id' => $this->client->id, 'event' => 'portal_invitation_resent', 'causer_id' => $this->manager->id]);
});

test('revocar desactiva, cierra al momento todas sus sesiones y su «Recordarme», anula la invitación y queda en la auditoría', function () {
    config(['session.driver' => 'database']);
    insertSession($this->portalUser);
    insertSession($this->portalUser);
    $keep = insertSession($this->admin);
    $remember = $this->portalUser->remember_token;
    Password::broker(InvitationController::BROKER)->createToken($this->portalUser);

    $this->actingAs($this->admin)
        ->post("/clientes/{$this->client->id}/portal/usuarios/{$this->portalUser->id}/revocar")
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $user = $this->portalUser->fresh();
    expect($user->is_active)->toBeFalse()
        ->and($user->remember_token)->not->toBe($remember)
        ->and(DB::table('sessions')->where('user_id', $this->portalUser->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('id', $keep)->exists())->toBeTrue()
        ->and(DB::table('invitation_tokens')->where('email', $user->email)->exists())->toBeFalse();

    $this->assertDatabaseHas('activity_log', [
        'log_name' => 'clients',
        'subject_id' => $this->client->id,
        'event' => 'portal_user_revoked',
        'causer_id' => $this->admin->id,
    ]);

    // Ya no entra: ni con la sesión que tenía ni iniciando sesión otra vez.
    $this->actingAs($user)->get('/portal')->assertRedirect(route('login'));
    auth()->logout();
    $user->forceFill(['password' => Hash::make('Una-Clave-Segura-2026')])->save();
    $this->post('/login', ['email' => $user->email, 'password' => 'Una-Clave-Segura-2026']);
    $this->assertGuest();
});

test('reactivar le devuelve el acceso; revocar dos veces o reactivar a quien ya entra se explica', function () {
    $this->actingAs($this->admin);

    $this->post("/clientes/{$this->client->id}/portal/usuarios/{$this->portalUser->id}/reactivar")
        ->assertSessionHasErrors(['user' => __('portal.access.errors.already_active')]);

    $this->post("/clientes/{$this->client->id}/portal/usuarios/{$this->portalUser->id}/revocar")->assertSessionHasNoErrors();
    $this->post("/clientes/{$this->client->id}/portal/usuarios/{$this->portalUser->id}/revocar")
        ->assertSessionHasErrors(['user' => __('portal.access.errors.already_revoked')]);
    $this->post("/clientes/{$this->client->id}/portal/usuarios/{$this->portalUser->id}/invitacion")
        ->assertSessionHasErrors(['user' => __('portal.access.errors.user_inactive')]);

    $this->post("/clientes/{$this->client->id}/portal/usuarios/{$this->portalUser->id}/reactivar")->assertSessionHasNoErrors();

    expect($this->portalUser->fresh()->is_active)->toBeTrue();
    $this->assertDatabaseHas('activity_log', ['subject_id' => $this->client->id, 'event' => 'portal_user_reactivated']);
    $this->actingAs($this->portalUser->fresh())->get('/portal')->assertOk();
});

test('matriz: admin, responsables y gestores de un proyecto del cliente gestionan; nadie más', function (string $actor, int $status) {
    Notification::fake();

    $user = match ($actor) {
        'admin' => $this->admin,
        'responsable' => userWithRole('department_manager'),
        'gestor' => $this->manager,
        'gestor de otro cliente' => $this->foreignManager,
        'empleado' => userWithRole('employee'),
        'cliente' => User::factory()->portalOf($this->client)->create(),
        default => null,
    };

    if ($user !== null) {
        $this->actingAs($user);
    }

    $urls = [
        ['post', "/clientes/{$this->client->id}/portal/usuarios", ['name' => 'Nueva', 'email' => "nueva-{$status}@lur.example"]],
        ['post', "/clientes/{$this->client->id}/portal/usuarios/{$this->portalUser->id}/invitacion", []],
        ['post', "/clientes/{$this->client->id}/portal/usuarios/{$this->portalUser->id}/revocar", []],
        ['post', "/clientes/{$this->client->id}/portal/usuarios/{$this->portalUser->id}/reactivar", []],
    ];

    foreach ($urls as [$method, $url, $payload]) {
        $response = $this->call(strtoupper($method), $url, $payload);

        if ($status === 302) {
            $response->assertStatus(302);
            expect($response->headers->get('Location'))->not->toContain('/portal')->not->toContain('/login');
        } elseif ($actor === 'cliente') {
            $response->assertRedirect(route('portal.home'));
        } elseif ($actor === 'invitado') {
            $response->assertRedirect(route('login'));
        } else {
            $response->assertStatus($status);
        }
    }

    $invited = User::query()->where('email', "nueva-{$status}@lur.example")->exists();
    expect($invited)->toBe($status === 302);
})->with([
    'admin' => ['admin', 302],
    'responsable' => ['responsable', 302],
    'gestor de un proyecto del cliente' => ['gestor', 302],
    'gestor de un proyecto de otro cliente' => ['gestor de otro cliente', 403],
    'empleado' => ['empleado', 403],
    'cliente' => ['cliente', 0],
    'invitado' => ['invitado', 0],
]);

test('una persona de otro cliente o del equipo en la URL da 404, aunque quien lo pida pueda gestionar', function () {
    $foreign = User::factory()->portalOf($this->other)->create();
    $employee = userWithRole('employee');

    $this->actingAs($this->admin);

    foreach (['invitacion', 'revocar', 'reactivar'] as $action) {
        $this->post("/clientes/{$this->client->id}/portal/usuarios/{$foreign->id}/{$action}")->assertNotFound();
        $this->post("/clientes/{$this->client->id}/portal/usuarios/{$employee->id}/{$action}")->assertNotFound();
        $this->post("/clientes/{$this->client->id}/portal/usuarios/999999/{$action}")->assertNotFound();
    }

    expect($foreign->fresh()->is_active)->toBeTrue()
        ->and($employee->fresh()->is_active)->toBeTrue();
});

test('la ficha del cliente lista sus usuarios del portal con la invitación y el último acceso', function () {
    Notification::fake();
    $this->travelTo('2026-09-27 10:00:00');

    $this->actingAs($this->admin);
    ($this->invite)()->assertSessionHasNoErrors();

    $revoked = User::factory()->portalOf($this->client)->create(['name' => 'Zoe Lur', 'is_active' => false]);
    LoginEvent::query()->forceCreate(['user_id' => $this->portalUser->id, 'email' => $this->portalUser->email, 'succeeded' => true, 'created_at' => '2026-09-20 08:30:00']);
    User::factory()->portalOf($this->other)->create();

    // Prop diferida: no pesa en la carga de la ficha; llega en la recarga que pide la página.
    $this->get("/clientes/{$this->client->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('clients/show')
            ->missing('portal')
            ->loadDeferredProps('portal', fn (Assert $reload) => $reload
                ->where('portal.can', ['manageUsers' => true, 'updateSettings' => true])
                ->where('portal.client_active', true)
                ->has('portal.users', 3)
                ->where('portal.users.0.name', 'Carmen Lur')
                ->where('portal.users.0.invitation', 'pending')
                ->where('portal.users.0.invitation_expires_at', '2026-10-04T10:00:00Z')
                ->where('portal.users.0.last_login_at', null)
                ->where('portal.users.1.name', 'Íñigo Lur')
                ->where('portal.users.1.invitation', 'accepted')
                ->where('portal.users.1.last_login_at', '2026-09-20T08:30:00Z')
                ->where('portal.users.2.name', 'Zoe Lur')
                ->where('portal.users.2.is_active', false)
                ->where('portal.settings', ['person_display' => 'name', 'entry_visibility' => 'approved', 'notify_thresholds' => false])
                ->missing('portal.users.0.password')));

    // Pasados los 7 días, la invitación sale caducada.
    $this->travel(8)->days();
    $this->get("/clientes/{$this->client->id}")
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('portal', fn (Assert $reload) => $reload
            ->where('portal.users.0.invitation', 'expired')));

    expect($revoked->fresh()->is_active)->toBeFalse();
});

test('un gestor ve la sección sin poder cambiar los ajustes; un empleado no la recibe', function () {
    $this->actingAs($this->manager)
        ->get("/clientes/{$this->client->id}")
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('portal', fn (Assert $reload) => $reload
            ->where('portal.can', ['manageUsers' => true, 'updateSettings' => false])
            ->has('portal.users', 1)));

    $this->actingAs(userWithRole('employee'))
        ->get("/clientes/{$this->client->id}")
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('portal', fn (Assert $reload) => $reload
            ->where('portal', null)));
});
