<?php

namespace App\Domain\Weeklies;

use App\Enums\AppModule;
use App\Models\Setting;

/**
 * Módulos activos (F-177, D-151): lo útil de la consola multi-tenant de WeeklySync para una sola
 * empresa. Se guardan en el ajuste `modules`; un módulo que no aparece está activo. Con uno apagado,
 * sus rutas responden 404 (middleware `module:<nombre>`) y la navegación lo oculta (prop
 * compartida config.modules).
 */
final class AppModules
{
    public static function enabled(AppModule $module): bool
    {
        return self::map()[$module->value];
    }

    /**
     * @return array<string, bool> todos los módulos, activos o no
     */
    public static function map(): array
    {
        $stored = Setting::get('modules');

        return self::normalize(is_array($stored) ? $stored : []);
    }

    /**
     * Completa y tipa un mapa módulo → activo (los que faltan, activos; los desconocidos, fuera).
     *
     * @param  array<array-key, mixed>  $values
     * @return array<string, bool>
     */
    public static function normalize(array $values): array
    {
        $map = [];

        foreach (AppModule::cases() as $module) {
            $map[$module->value] = (bool) ($values[$module->value] ?? true);
        }

        return $map;
    }
}
