<?php

namespace App\Domain\DayPlan;

use App\Domain\Time\Week;
use App\Enums\DayPlanItemStatus;
use App\Models\DayPlanItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Semana del equipo (`/dia/semana?semana=2026-W41`, docs/PLAN-CARGAS.md §4.3; D-251): personas × días
 * de lunes a viernes (y el fin de semana si alguien trabaja o planifica), con el estado de cada día y
 * sus líneas (clic en la celda). «Hechas / planificadas» y el total de la semana, solo a quien puede
 * ver las cifras de esa persona; el resto ve si hubo plan.
 */
final class TeamWeek
{
    public function __construct(private readonly DayPlanTeam $team) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $viewer, Week $week, ?int $departmentId): array
    {
        $today = DayPlanCalendar::today()->toDateString();
        $from = $week->start;
        $to = $week->end();
        $people = $this->team->people($departmentId);
        $ids = $people->modelKeys();
        $days = $this->team->days($people, $from, $to);

        $items = DayPlanItem::query()
            ->whereIn('user_id', $ids)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->with(DayPlanPresenter::WITH)
            ->orderBy('date')
            ->orderBy('position')
            ->orderBy('id')
            ->get();
        $byPersonDay = $items->groupBy(fn (DayPlanItem $item): string => $item->user_id.'|'.$item->date->toDateString());
        $figuresFor = array_flip(array_values(array_filter($ids, fn (int $id): bool => DayPlanAccess::seesFigures($viewer, $people->find($id) ?? $viewer))));
        $logged = DayPlanPresenter::loggedByItem(array_values($items->filter(fn (DayPlanItem $item): bool => isset($figuresFor[$item->user_id]))->modelKeys()));

        $dates = [];

        for ($day = $from; $day <= $to; $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        // El fin de semana solo si alguien tiene jornada o líneas.
        $shown = array_values(array_filter($dates, function (string $date) use ($days, $byPersonDay, $ids): bool {
            if (CarbonImmutable::parse($date)->isWeekday()) {
                return true;
            }

            foreach ($ids as $id) {
                if (($days[$id][$date]['capacity'] ?? 0) > 0 || $byPersonDay->has($id.'|'.$date)) {
                    return true;
                }
            }

            return false;
        }));

        $rows = [];

        foreach ($people as $person) {
            $figures = isset($figuresFor[$person->id]);
            $total = ['done' => 0, 'total' => 0, 'days_with_plan' => 0];
            $cells = [];

            foreach ($shown as $date) {
                /** @var Collection<int, DayPlanItem> $lines */
                $lines = $byPersonDay->get($person->id.'|'.$date, collect());
                $info = $days[$person->id][$date] ?? ['capacity' => 0, 'holiday' => null, 'absence' => null, 'away' => false];
                $state = DayPlanTeam::state($info, $lines->isNotEmpty(), $date, $today, DayPlanCalendar::pastDeadline($date));
                $done = $lines->where('status', DayPlanItemStatus::Done)->count();

                $cells[] = [
                    'date' => $date,
                    'state' => $state,
                    'reason' => DayPlanTeam::reason($info, $state, $viewer, $person),
                    'items' => array_values($lines->map(fn (DayPlanItem $item): array => DayPlanPresenter::line($item, $figures, $logged))->all()),
                    'figures' => $figures && $lines->isNotEmpty() ? [
                        'done' => $done,
                        'total' => $lines->count(),
                        'carried' => $lines->where('carry_count', '>', 0)->count(),
                    ] : null,
                ];

                $total['done'] += $done;
                $total['total'] += $lines->count();
                $total['days_with_plan'] += $lines->isNotEmpty() && $date <= $today ? 1 : 0;
            }

            $rows[] = [
                'user' => TeamDay::person($person),
                'days' => $cells,
                'figures' => $figures ? $total : null,
            ];
        }

        return [
            'week' => $week->iso(),
            'previous_week' => Week::containing($from->subWeek())->iso(),
            'next_week' => Week::containing($from->addWeek())->iso(),
            'current_week' => Week::current()->iso(),
            'days' => $shown,
            'today' => $today,
            'department' => $departmentId ?? 'all',
            'departments' => TeamDay::departments(),
            'rows' => $rows,
        ];
    }
}
