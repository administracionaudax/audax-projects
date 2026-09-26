<?php

namespace App\Console\Commands;

use App\Auth\SessionTerminator;
use App\Domain\Admin\WorkScheduleVersions;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\DefaultSettingsSeeder;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\InternalProjectSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TaskStatusesSeeder;
use Database\Seeders\TaskTypesSeeder;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

/**
 * Primer arranque (SPEC §14, D-010). Idempotente: se puede repetir sin duplicar nada ni pisar lo
 * que el admin haya editado.
 *
 * - Crea roles y permisos, ajustes por defecto y departamentos.
 * - Crea los estados de tarea por defecto (si no hay ninguno) y los tipos de tarea por defecto,
 *   enlazados por nombre con los departamentos.
 * - Crea el primer admin con una contraseña aleatoria que NUNCA se muestra, y emite un enlace de
 *   restablecimiento de un solo uso para que el admin fije la suya.
 * - Con el admin ya creado: le da su jornada por defecto (si no tiene) y crea el proyecto interno
 *   «Interno – Agencia» (Project::INTERNAL_CODE, sin cliente, no facturable, gestor principal el
 *   primer admin) con las tareas Reuniones, Formación, Gestión y Comercial (SPEC §7, D-033).
 */
#[Signature('app:install
    {--name= : Nombre del primer administrador}
    {--email= : Correo electrónico del primer administrador}
    {--reset-link : Emite un nuevo enlace de restablecimiento si el administrador ya existe}
    {--promote : Convierte en administrador a un usuario que ya existe con ese correo}')]
#[Description('Instala los datos base (roles, ajustes, departamentos, estados, tipos y proyecto interno) y crea el primer administrador')]
class InstallCommand extends Command
{
    public function handle(): int
    {
        $this->components->info('Instalando Audax Proyectos');

        $this->components->task('Roles y permisos', fn () => $this->runSeeder(RolesAndPermissionsSeeder::class));
        $this->components->task('Ajustes por defecto', fn () => $this->runSeeder(DefaultSettingsSeeder::class));
        $this->components->task('Departamentos', fn () => $this->runSeeder(DepartmentsSeeder::class));
        $this->components->task('Estados de tarea', fn () => $this->runSeeder(TaskStatusesSeeder::class));
        $this->components->task('Tipos de tarea', fn () => $this->runSeeder(TaskTypesSeeder::class));

        $result = $this->installFirstAdmin();

        if ($result === self::SUCCESS) {
            $this->installAdminDefaults();
        }

        return $result;
    }

    /**
     * Jornada del primer admin y proyecto interno, que necesita un gestor principal (D-032).
     */
    private function installAdminDefaults(): void
    {
        $admin = User::role(Role::Admin->value)->where('is_active', true)->orderBy('id')->first();

        if ($admin === null) {
            return;
        }

        $this->components->task('Jornada del administrador', function () use ($admin): bool {
            if (! $admin->workSchedules()->exists()) {
                $this->laravel->make(WorkScheduleVersions::class)->createDefault($admin);
            }

            return true;
        });

        $this->components->task('Proyecto interno', fn () => $this->runSeeder(InternalProjectSeeder::class, ['ownerId' => $admin->id]));
    }

    /**
     * @param  class-string<Seeder>  $seeder
     * @param  array<string, mixed>  $parameters
     */
    private function runSeeder(string $seeder, array $parameters = []): bool
    {
        /** @var Seeder $instance */
        $instance = $this->laravel->make($seeder);
        $instance->setContainer($this->laravel)->setCommand($this)->__invoke($parameters);

        return true;
    }

    private function installFirstAdmin(): int
    {
        $existingAdmin = User::role(Role::Admin->value)->orderBy('id')->first();

        if ($existingAdmin !== null) {
            $this->components->info("Ya existe un administrador ({$existingAdmin->email}); no se crea otro.");

            if ($this->option('reset-link')) {
                $this->printResetLink($existingAdmin);
            }

            return self::SUCCESS;
        }

        $name = $this->option('name') ?: ($this->input->isInteractive()
            ? text(label: 'Nombre del administrador', required: true)
            : null);
        $email = $this->option('email') ?: ($this->input->isInteractive()
            ? text(label: 'Correo electrónico del administrador', required: true)
            : null);

        $validator = Validator::make(
            ['name' => $name, 'email' => is_string($email) ? Str::lower(trim($email)) : $email],
            ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'string', 'email', 'max:255']],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        /** @var array{name: string, email: string} $data */
        $data = $validator->validated();

        $existing = User::query()->where('email', $data['email'])->first();

        // Un usuario que ya existe (un cliente, alguien desactivado) no se convierte en admin sin
        // pedirlo de forma expresa.
        if ($existing !== null && ! $this->option('promote')) {
            $this->components->error("Ya existe un usuario con el correo {$data['email']} y no se ha tocado.");
            $this->line('  Para convertirlo en el primer administrador, vuelve a ejecutar el comando con --promote.');

            return self::FAILURE;
        }

        $admin = DB::transaction(function () use ($data, $existing): User {
            if ($existing !== null) {
                // Se reactiva, se le pone una contraseña aleatoria que nadie conoce y se cierran sus
                // sesiones y su «Recordarme»: fija la contraseña con el enlace, como un admin nuevo.
                $existing->forceFill(['is_active' => true, 'password' => Str::password(40)])->save();
                $this->laravel->make(SessionTerminator::class)->destroyAll($existing);
                $existing->syncRoles([Role::Admin->value]);

                return $existing;
            }

            $user = User::query()->forceCreate([
                'email' => $data['email'],
                'name' => $data['name'],
                // Contraseña aleatoria que nadie conoce: el admin fija la suya con el enlace.
                'password' => Str::password(40),
                'email_verified_at' => now(),
                'is_active' => true,
            ]);

            $user->syncRoles([Role::Admin->value]);

            return $user;
        });

        $this->components->info($existing !== null
            ? "Usuario existente convertido en administrador: {$admin->email}"
            : "Administrador creado: {$admin->email}");
        $this->printResetLink($admin);

        return self::SUCCESS;
    }

    private function printResetLink(User $user): void
    {
        /** @var PasswordBroker $broker */
        $broker = Password::broker(config('fortify.passwords'));
        $token = $broker->createToken($user);

        $url = route('password.reset', ['token' => $token, 'email' => $user->email]);
        $minutes = (int) config('auth.passwords.'.config('fortify.passwords', 'users').'.expire', 60);

        $this->newLine();
        $this->line('  Enlace de un solo uso para fijar la contraseña (caduca en '.$minutes.' minutos):');
        $this->line('  <fg=cyan>'.$url.'</>');
        $this->newLine();
        $this->line('  Si caduca, vuelve a ejecutar: php artisan app:install --reset-link');
    }
}
