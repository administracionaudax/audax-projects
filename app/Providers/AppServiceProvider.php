<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Gates globales que un colaborador externo nunca tiene (D-134): los permisos de
     * Permission::cases() y las de bolsas, aprobaciones, bloqueo y Horizon.
     */
    public const array COLLABORATOR_DENIED = [
        'manage-users',
        'manage-settings',
        'view-financials',
        'view-hour-banks',
        'approve-time',
        'lock-time',
        'viewHorizon',
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (self::guardsRuntime($this->app->runningInConsole(), $_SERVER['argv'][1] ?? null)) {
            $this->guardSharedRedis();
        }

        $this->configureDefaults();
        $this->configureGates();
    }

    /**
     * Permisos globales (SPEC §5). Los permisos por proyecto están en app/Policies.
     */
    protected function configureGates(): void
    {
        // Un usuario desactivado no puede nada, aunque conserve sus roles (SPEC §14).
        Gate::before(fn (User $user): ?bool => $user->isActive() ? null : false);

        // Un colaborador externo (D-134) nunca ve datos económicos ni bolsas, no aprueba ni bloquea
        // horas y no administra nada, aunque alguien le diera el permiso por error.
        Gate::before(fn (User $user, string $ability): ?bool => in_array($ability, self::COLLABORATOR_DENIED, true) && $user->isCollaborator() ? false : null);

        // Una gate por permiso global (config permission.register_permission_check_method = false).
        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user): bool => $user->checkPermissionTo($permission->value));
        }

        // Vista global de bolsas: admin, responsables y gestores de algún proyecto (D-005, D-035).
        Gate::define('view-hour-banks', fn (User $user): bool => $user->hasAnyRole([
            Role::Admin->value,
            Role::DepartmentManager->value,
        ]) || $user->managedProjects()->exists());

        // Aprobaciones de horas (/horas/aprobaciones): responsables y admins (D-020).
        Gate::define('approve-time', fn (User $user): bool => $user->hasAnyRole([
            Role::Admin->value,
            Role::DepartmentManager->value,
        ]));

        // Bloquear y desbloquear horas al facturar: solo admins (SPEC §7, D-034).
        Gate::define('lock-time', fn (User $user): bool => $user->isAdmin());

        // Datos económicos (costes, tarifas, rentabilidad): la gate view-financials definida arriba
        // exige el permiso del mismo nombre, que el admin recibe por defecto (RolesAndPermissionsSeeder).
    }

    /**
     * Comandos de consola que mantienen conexiones con Redis (colas, Horizon, scheduler, Reverb).
     *
     * @var list<string>
     */
    public const array LONG_RUNNING_COMMANDS = [
        'horizon', 'horizon:work', 'horizon:supervisor',
        'queue:work', 'queue:listen',
        'schedule:work', 'schedule:run',
        'reverb:start',
    ];

    /**
     * La guarda de Redis se aplica a las peticiones web y a los procesos de larga duración, que son
     * los que usarían el Redis compartido. No a comandos de mantenimiento como package:discover,
     * que Composer lanza sin .env (y por tanto como "production") en una instalación limpia o en la CI.
     */
    public static function guardsRuntime(bool $runningInConsole, ?string $command): bool
    {
        return ! $runningInConsole || in_array($command, self::LONG_RUNNING_COMMANDS, true);
    }

    /**
     * El servidor tiene un Redis compartido en el 6379, sin contraseña y con datos de otras webs
     * (docs/SERVIDOR.md §5). La app solo puede usar su Valkey propio: si la configuración apunta
     * al 6379 o no lleva contraseña, se aborta el arranque en lugar de caer en silencio en el compartido.
     */
    public function guardSharedRedis(): void
    {
        if ($this->app->environment(['local', 'testing'])) {
            return;
        }

        /** @var array<string, mixed> $redis */
        $redis = (array) config('database.redis', []);

        foreach ($redis as $connection => $settings) {
            if (in_array($connection, ['client', 'options', 'clusters'], true) || ! is_array($settings)) {
                continue;
            }

            $port = (string) ($settings['port'] ?? '');
            $password = (string) ($settings['password'] ?? '');

            if ($port === '6379' || $password === '') {
                throw new RuntimeException("Redis [{$connection}] mal configurado: debe usarse el Valkey propio (puerto 16379, con contraseña).");
            }
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Consultas N+1 (SPEC §19): en local y en los tests, cargar una relación sin eager loading falla.
        Model::preventLazyLoading(! app()->isProduction());

        // Los Resources se envían tal cual a Inertia, sin el envoltorio {data: …} (también los
        // anidados): el contrato es resources/js/types/domain.ts. Las colecciones PAGINADAS siguen
        // llegando como {data, links, meta}.
        JsonResource::withoutWrapping();

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // Sin ->uncompromised(): consultaría la API externa de Have I Been Pwned con un fragmento del
        // SHA-1 de cada contraseña, y el SPEC §15 prohíbe enviar datos a terceros (todo autoalojado).
        Password::defaults(fn (): ?Password => app()->environment(['local', 'testing'])
            ? null
            : Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols(),
        );
    }
}
