<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
    }

    /**
     * El servidor tiene un Redis compartido en el 6379, sin contraseña y con datos de otras webs
     * (docs/SERVIDOR.md §5). La app solo puede usar su Valkey propio: si la configuración apunta
     * al 6379 o no lleva contraseña, se aborta el arranque en lugar de caer en silencio en el compartido.
     */
    protected function guardSharedRedis(): void
    {
        if ($this->app->environment(['local', 'testing'])) {
            return;
        }

        foreach (['default', 'cache'] as $connection) {
            $port = (string) config("database.redis.{$connection}.port");
            $password = (string) config("database.redis.{$connection}.password");

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
