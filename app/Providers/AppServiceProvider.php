<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
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
        $this->guardSharedRedis();
        $this->configureDefaults();
        $this->configureGates();
    }

    /**
     * Permisos globales (SPEC §5). Los permisos por proyecto llegan con Policies en la Fase 1.
     */
    protected function configureGates(): void
    {
        // Un usuario desactivado no puede nada, aunque conserve sus roles (SPEC §14).
        Gate::before(fn (User $user): ?bool => $user->isActive() ? null : false);

        // Una gate por permiso global (config permission.register_permission_check_method = false).
        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user): bool => $user->checkPermissionTo($permission->value));
        }

        // Bolsas de horas: admin y responsables; en la Fase 1, también los gestores de proyecto (D-005).
        Gate::define('view-hour-banks', fn (User $user): bool => $user->hasAnyRole([
            Role::Admin->value,
            Role::DepartmentManager->value,
        ]));

        // Datos económicos (costes, tarifas, rentabilidad): la gate view-financials definida arriba
        // exige el permiso del mismo nombre, que el admin recibe por defecto (RolesAndPermissionsSeeder).
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

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->environment(['local', 'testing'])
            ? null
            : Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised(),
        );
    }
}
