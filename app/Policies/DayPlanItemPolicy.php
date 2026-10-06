<?php

namespace App\Policies;

use App\Domain\DayPlan\DayPlanAccess;
use App\Models\DayPlanItem;
use App\Models\User;

/**
 * Líneas del plan del día (docs/PLAN-CARGAS.md §8, D-251): cada persona escribe, cierra, pasa y borra
 * solo las suyas; nadie edita la línea de otro (tampoco un admin). Las ve toda la plantilla que usa el
 * plan del día; comentan la persona, su responsable y los admins (DayPlanAccess).
 */
class DayPlanItemPolicy
{
    public function view(User $user, DayPlanItem $item): bool
    {
        return DayPlanAccess::uses($user);
    }

    public function update(User $user, DayPlanItem $item): bool
    {
        return DayPlanAccess::uses($user) && $item->user_id === $user->id;
    }

    public function comment(User $user, DayPlanItem $item): bool
    {
        return DayPlanAccess::uses($user) && DayPlanAccess::comments($user, $item->user);
    }
}
