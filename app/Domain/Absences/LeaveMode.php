<?php

namespace App\Domain\Absences;

use App\Domain\Weeklies\AppModules;
use App\Enums\AppModule;
use App\Models\User;

/**
 * ¿Funcionan las ausencias como en Woffu (Fase 11, R3; D-360)? Solo con el módulo `people` visible
 * para quien actúa: catálogo completo, saldos, justificantes, horas con franja, segundo nivel,
 * «Pedir cancelación», días bloqueados y avisos de antelación. Con el módulo apagado, /ausencias
 * sigue exactamente como en la Fase 3 (los cinco tipos, sin saldos ni justificantes): la plantilla
 * lo usa hoy para la capacidad y Woffu sigue llevando los saldos hasta R5.
 *
 * - on(): para las pantallas y las reglas de lo que hace una persona (modo de prueba incluido para
 *   los admins, D-239).
 * - enabled(): para los procesos automáticos y los avisos a otras personas (nunca en modo de prueba).
 */
final class LeaveMode
{
    public static function on(?User $user): bool
    {
        return $user !== null && AppModules::visibleTo($user, AppModule::People);
    }

    public static function enabled(): bool
    {
        return AppModules::enabled(AppModule::People);
    }
}
