<?php

namespace App\Domain\DayPlan;

use App\Domain\Weeklies\AppModules;
use App\Enums\AppModule;
use App\Models\User;

/**
 * Quién usa y quién ve qué del plan del día (docs/PLAN-CARGAS.md §8 y §15, P1 a; D-251):
 *
 * - **Lo usa** (escribe el suyo y ve el de los demás): la plantilla interna (admin, responsables y
 *   empleados), como la Weekly (D-147); nunca un colaborador externo (D-134) ni un cliente. Con el
 *   módulo `day_plan` visible para esa persona (apagado, solo los admins en modo de prueba, D-239).
 * - **Textos y checks**: toda la plantilla ve los de todos (como la lista «daily» de ClickUp).
 * - **Cifras** (horas previstas, imputadas, jornada, cumplimiento, temporizador en marcha) y
 *   **comentarios**: solo la propia persona, su responsable (el de su departamento) y los admins,
 *   la misma regla que el tipo de ausencia (canSeeAbsencesOf, D-088). No hay ranking.
 * - **Comentar** una línea: su responsable y los admins; la persona puede contestar. Nadie edita
 *   la línea de otro.
 */
final class DayPlanAccess
{
    public static function uses(?User $user): bool
    {
        return $user !== null
            && $user->isActive()
            && ! $user->isCollaborator()
            && $user->writesWeeklies()
            && AppModules::visibleTo($user, AppModule::DayPlan);
    }

    /** ¿Ve $viewer las cifras y los comentarios del plan de $subject? */
    public static function seesFigures(User $viewer, User $subject): bool
    {
        return $viewer->canSeeAbsencesOf($subject);
    }

    /** ¿Puede $viewer comentar una línea de $subject? Su responsable, un admin o ella misma. */
    public static function comments(User $viewer, User $subject): bool
    {
        return $viewer->id === $subject->id || $viewer->isAdmin() || $viewer->supervises($subject);
    }

    /** ¿Puede $viewer recordar a $subject que escriba su plan? Su responsable o un admin. */
    public static function reminds(User $viewer, User $subject): bool
    {
        return $viewer->id !== $subject->id && ($viewer->isAdmin() || $viewer->supervises($subject));
    }
}
