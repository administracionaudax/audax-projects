<?php

namespace App\Domain\Weeklies;

use App\Enums\AppModule;
use App\Models\Setting;
use App\Models\User;

/**
 * Módulos activos (F-177, D-151): lo útil de la consola multi-tenant de WeeklySync para una sola
 * empresa. Se guardan en el ajuste `modules`; un módulo que no aparece está activo. Con uno apagado,
 * sus rutas responden 404 (middleware `module:<nombre>`) y la navegación lo oculta (prop
 * compartida config.modules).
 *
 * Modo de prueba (D-239, ajuste `modules_preview`): con un módulo apagado, los admins lo ven y lo usan
 * como si estuviera encendido; el resto de la plantilla, no. Dos preguntas distintas:
 * - enabled(): ¿está encendido de verdad? La usan los procesos automáticos (comandos programados,
 *   recordatorios, resúmenes, envíos de informes) y todo lo que avisa a otras personas: en modo de
 *   prueba no hacen nada.
 * - visibleTo(): ¿lo ve y lo usa esta persona? La usan las rutas, la navegación, Inicio, la
 *   búsqueda, los canales en tiempo real y las páginas.
 *
 * Exclusiones (D-245, ajuste `module_excluded_users`): una persona de la lista no ve el módulo aunque
 * sea admin y esté encendido o en modo de prueba (p. ej., Facturación solo para dos de los tres admins).
 */
final class AppModules
{
    public static function enabled(AppModule $module): bool
    {
        return self::map()[$module->value];
    }

    /** ¿Está activo el modo de prueba (ajuste `modules_preview`)? */
    public static function previewMode(): bool
    {
        return (bool) Setting::get('modules_preview', false);
    }

    /** ¿Ve y usa $user el módulo? Encendido, o apagado y en modo de prueba para un admin. */
    public static function visibleTo(?User $user, AppModule $module): bool
    {
        return ! self::excluded($user, $module) && (self::enabled($module) || self::previewing($user, $module));
    }

    /** ¿Está $user en la lista de personas sin acceso a $module (D-245)? */
    public static function excluded(?User $user, AppModule $module): bool
    {
        return $user !== null && in_array($user->id, self::excludedIds($module), true);
    }

    /**
     * @return list<int>
     */
    public static function excludedIds(AppModule $module): array
    {
        $stored = Setting::get('module_excluded_users', []);
        $ids = is_array($stored) && is_array($stored[$module->value] ?? null) ? $stored[$module->value] : [];

        return array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))));
    }

    /**
     * @param  list<int>  $userIds
     */
    public static function setExcluded(AppModule $module, array $userIds): void
    {
        $stored = Setting::get('module_excluded_users', []);
        $stored = is_array($stored) ? $stored : [];
        $stored[$module->value] = array_values(array_unique(array_map('intval', $userIds)));

        Setting::set('module_excluded_users', $stored);
    }

    /** ¿Ve $user el módulo SOLO por el modo de prueba (apagado de verdad y $user, admin)? */
    public static function previewing(?User $user, AppModule $module): bool
    {
        return ! self::enabled($module) && self::previewer($user);
    }

    /**
     * Mapa módulo → visible para $user (la prop compartida config.modules).
     *
     * @return array<string, bool>
     */
    public static function mapFor(?User $user): array
    {
        $map = self::map();

        if (self::previewer($user)) {
            $map = array_map(fn (): bool => true, $map);
        }

        foreach (AppModule::cases() as $module) {
            if (self::excluded($user, $module)) {
                $map[$module->value] = false;
            }
        }

        return $map;
    }

    /**
     * Módulos que $user ve solo por el modo de prueba (config.modules_preview).
     *
     * @return list<string>
     */
    public static function previewedBy(?User $user): array
    {
        if (! self::previewer($user)) {
            return [];
        }

        return array_values(array_filter(
            array_keys(array_filter(self::map(), fn (bool $enabled): bool => ! $enabled)),
            fn (string $module): bool => ! self::excluded($user, AppModule::from($module)),
        ));
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

    /** Admin con el modo de prueba activo. */
    private static function previewer(?User $user): bool
    {
        return $user !== null && self::previewMode() && $user->isAdmin();
    }
}
