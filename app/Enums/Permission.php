<?php

namespace App\Enums;

/**
 * Permisos globales (spatie/laravel-permission). Los permisos por proyecto se resuelven con Policies.
 */
enum Permission: string
{
    case ManageUsers = 'manage-users';
    case ManageSettings = 'manage-settings';
    case ViewFinancials = 'view-financials';
    /** La Weekly (D-147): informe, audio, plazo, cierre, exenciones, avisos, ayuda y estados de las sugerencias. */
    case ManageWeeklies = 'manage-weeklies';
    /** La Previsión (D-284): crear y editar proyectos previstos y sus asignaciones; admins y responsables. */
    case ManageForecast = 'manage-forecast';
    /** RR. HH. (Fase 11, D-330): la jornada de toda la plantilla, sus correcciones y los datos laborales. */
    case ManagePeople = 'manage-people';
    /** Emisión propia (PLAN-EMISION, E1; D-418): emitir, anular y rectificar facturas. Admins por defecto. */
    case ManageBilling = 'manage-billing';

    public function label(): string
    {
        return match ($this) {
            self::ManageUsers => 'Gestionar usuarios',
            self::ManageSettings => 'Gestionar ajustes',
            self::ViewFinancials => 'Ver datos económicos',
            self::ManageWeeklies => 'Gestionar las weeklies',
            self::ManageForecast => 'Gestionar la previsión',
            self::ManagePeople => 'Gestionar RR. HH.',
            self::ManageBilling => 'Emitir facturas',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $permission): string => $permission->value, self::cases());
    }
}
