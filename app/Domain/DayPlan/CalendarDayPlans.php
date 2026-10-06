<?php

namespace App\Domain\DayPlan;

use App\Models\DayPlanItem;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * El plan del día en la vista Día del calendario del equipo, por personas (docs/PLAN-CARGAS.md §10;
 * D-254): las líneas de cada persona de la vista con los permisos de siempre (D-251: textos y checks
 * para todos; horas, solo la persona, su responsable y los admins; sin comentarios). Null para quien
 * no usa el plan del día o con el módulo apagado.
 */
final class CalendarDayPlans
{
    /**
     * @param  list<int>  $userIds
     * @return array<int, list<array<string, mixed>>>|null persona → líneas
     */
    public function for(User $viewer, array $userIds, CarbonImmutable $date): ?array
    {
        if (! DayPlanAccess::uses($viewer)) {
            return null;
        }

        if ($userIds === []) {
            return [];
        }

        $items = DayPlanItem::query()
            ->whereIn('user_id', $userIds)
            ->where('date', $date->toDateString())
            ->with([...DayPlanPresenter::WITH, 'user'])
            ->orderBy('position')
            ->orderBy('id')
            ->get();
        $figures = [];

        foreach ($items->pluck('user')->unique('id') as $person) {
            $figures[$person->id] = DayPlanAccess::seesFigures($viewer, $person);
        }

        $logged = DayPlanPresenter::loggedByItem(array_values($items->filter(fn (DayPlanItem $item): bool => $figures[$item->user_id] ?? false)->modelKeys()));
        $plans = [];

        foreach ($items as $item) {
            $plans[$item->user_id][] = DayPlanPresenter::line($item, $figures[$item->user_id] ?? false, $logged);
        }

        return $plans;
    }
}
