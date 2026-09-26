<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Department;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Spatie\Permission\Models\Role as RoleModel;

/**
 * Ejecuta app:install y devuelve [código de salida, salida].
 *
 * @param  array<string, mixed>  $options
 * @return array{0: int, 1: string}
 */
function runInstall(array $options = []): array
{
    $exitCode = Artisan::call('app:install', [
        '--name' => 'Ana Admin',
        '--email' => 'ana@audaxstudio.com',
        '--no-interaction' => true,
        ...$options,
    ]);

    return [$exitCode, Artisan::output()];
}

test('app:install crea roles, permisos, ajustes, departamentos y el primer admin', function () {
    [$exitCode, $output] = runInstall();

    expect($exitCode)->toBe(0)
        ->and(RoleModel::query()->pluck('name')->sort()->values()->all())->toBe(collect(Role::values())->sort()->values()->all())
        ->and(Department::query()->pluck('color', 'name')->all())->toBe([
            'Diseño' => '#0171FF',
            'Desarrollo' => '#179FA5',
            'Marketing' => '#5E2DAD',
        ])
        ->and(Setting::query()->count())->toBe(count(Setting::DEFAULTS))
        ->and(Setting::get('require_2fa'))->toBeFalse()
        ->and(Setting::get('hour_bank_alert_thresholds'))->toBe([75, 90, 100])
        ->and(Setting::get('company_name'))->toBe('Audax Studio');

    $admin = User::query()->where('email', 'ana@audaxstudio.com')->sole();

    expect($admin->hasRole(Role::Admin->value))->toBeTrue()
        ->and($admin->hasPermissionTo(Permission::ViewFinancials->value))->toBeTrue()
        ->and($admin->is_active)->toBeTrue();
});

test('app:install es idempotente: dos ejecuciones dejan un solo admin y los mismos datos', function () {
    runInstall();
    Setting::set('company_name', 'Nombre editado');

    [$exitCode, $output] = runInstall();

    expect($exitCode)->toBe(0)
        ->and(User::role(Role::Admin->value)->count())->toBe(1)
        ->and(User::query()->count())->toBe(1)
        ->and(RoleModel::query()->count())->toBe(count(Role::cases()))
        ->and(Department::query()->count())->toBe(3)
        ->and(Setting::query()->count())->toBe(count(Setting::DEFAULTS))
        ->and(Setting::get('company_name'))->toBe('Nombre editado')
        ->and($output)->toContain('Ya existe un administrador');
});

test('app:install no imprime la contraseña e imprime un enlace de restablecimiento válido', function () {
    [, $output] = runInstall();

    $admin = User::query()->where('email', 'ana@audaxstudio.com')->sole();

    // Ninguna palabra de la salida es la contraseña generada.
    foreach (preg_split('/\s+/', strip_tags($output)) ?: [] as $word) {
        if ($word !== '') {
            expect(Hash::check($word, $admin->password))->toBeFalse();
        }
    }

    expect($output)->not->toContain('password:')
        ->and($output)->not->toContain('Contraseña:');

    preg_match('#/reset-password/([A-Za-z0-9]+)\?email=([^\s]+)#', $output, $matches);

    expect($matches)->toHaveCount(3)
        ->and(urldecode($matches[2]))->toBe('ana@audaxstudio.com')
        ->and(Password::broker()->tokenExists($admin, $matches[1]))->toBeTrue();
});

test('el enlace de app:install sirve una sola vez para fijar la contraseña', function () {
    [, $output] = runInstall();
    preg_match('#/reset-password/([A-Za-z0-9]+)#', $output, $matches);

    $payload = [
        'token' => $matches[1],
        'email' => 'ana@audaxstudio.com',
        'password' => 'una-contraseña-larga',
        'password_confirmation' => 'una-contraseña-larga',
    ];

    $this->post(route('password.update'), $payload)->assertSessionHasNoErrors();
    $this->post(route('password.update'), $payload)->assertSessionHasErrors('email');

    $this->post(route('login.store'), ['email' => 'ana@audaxstudio.com', 'password' => 'una-contraseña-larga']);
    $this->assertAuthenticated();
});

test('app:install --reset-link emite un enlace nuevo para el admin existente', function () {
    runInstall();

    [, $output] = runInstall(['--reset-link' => true]);

    expect($output)->toContain('/reset-password/');
});

test('app:install falla sin correo en modo no interactivo y no crea el admin', function () {
    $exitCode = Artisan::call('app:install', ['--no-interaction' => true]);

    expect($exitCode)->toBe(1)
        ->and(User::query()->count())->toBe(0)
        ->and(Department::query()->count())->toBe(3);
});

test('app:install no convierte en admin a un usuario que ya existe con ese correo', function () {
    $client = User::factory()->client()->inactive()->create(['email' => 'ana@audaxstudio.com']);
    $password = $client->password;

    [$exitCode, $output] = runInstall();

    $client->refresh();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('--promote')
        ->and($output)->not->toContain('Administrador creado')
        ->and($client->hasRole(Role::Admin->value))->toBeFalse()
        ->and($client->hasRole(Role::Client->value))->toBeTrue()
        ->and($client->is_active)->toBeFalse()
        ->and($client->password)->toBe($password);
});

test('app:install --promote convierte al usuario existente en admin, lo reactiva y le cambia la contraseña', function () {
    config(['session.driver' => 'database']);

    $user = User::factory()->client()->inactive()->create([
        'email' => 'ana@audaxstudio.com',
        'remember_token' => 'token-anterior',
    ]);
    insertSession($user);

    [$exitCode, $output] = runInstall(['--promote' => true]);

    $user->refresh();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Usuario existente convertido en administrador')
        ->and($output)->toContain('/reset-password/')
        ->and($user->getRoleNames()->all())->toBe([Role::Admin->value])
        ->and($user->is_active)->toBeTrue()
        ->and(Hash::check('password', $user->password))->toBeFalse()
        ->and($user->remember_token)->not->toBe('token-anterior')
        ->and(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse();
});

test('app:install rechaza un correo no válido', function () {
    [$exitCode] = runInstall(['--email' => 'no-es-un-correo']);

    expect($exitCode)->toBe(1)->and(User::query()->count())->toBe(0);
});
