<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WeeklyCycle;
use Illuminate\Support\Facades\Gate;

/**
 * Semanas de la weekly (D-147):
 * - las ven (histórico, informe, estado del equipo) los internos de plantilla (`use-weeklies`),
 * - las gestionan (abrir, informe y audio, plazo, cierre y borrado) quienes tienen
 *   `manage-weeklies`: admins y responsables de departamento,
 * - el plazo, el cierre y las exenciones solo con la semana activa (F-068 y F-089).
 * Nunca un colaborador externo (D-134) ni un cliente; un desactivado no puede nada (Gate::before).
 */
class WeeklyCyclePolicy
{
    public function viewAny(User $user): bool
    {
        return Gate::forUser($user)->allows('use-weeklies');
    }

    public function view(User $user, WeeklyCycle $cycle): bool
    {
        return $this->viewAny($user);
    }

    /** Abrir una semana a mano (F-040), si no hay ninguna activa. */
    public function create(User $user): bool
    {
        return $this->manages($user);
    }

    /** Generar, editar o regenerar el informe y el audio (F-072, F-077 y F-084). */
    public function update(User $user, WeeklyCycle $cycle): bool
    {
        return $this->manages($user);
    }

    public function generate(User $user, WeeklyCycle $cycle): bool
    {
        return $this->update($user, $cycle);
    }

    /** Ampliar el plazo (F-068): solo con la semana activa. */
    public function extendDeadline(User $user, WeeklyCycle $cycle): bool
    {
        return $this->manages($user) && $cycle->isActive();
    }

    /** Cerrar (F-089): solo la activa. Que haya texto y audio lo comprueba el cierre. */
    public function close(User $user, WeeklyCycle $cycle): bool
    {
        return $this->manages($user) && $cycle->isActive();
    }

    /** Borrar (F-069): irreversible, borra sus envíos. */
    public function delete(User $user, WeeklyCycle $cycle): bool
    {
        return $this->manages($user);
    }

    /** Recordar a una persona pendiente (F-037 y F-110). */
    public function remind(User $user, WeeklyCycle $cycle): bool
    {
        return $this->manages($user) && $cycle->isActive();
    }

    private function manages(User $user): bool
    {
        return Gate::forUser($user)->allows('manage-weeklies');
    }
}
