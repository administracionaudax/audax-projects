<?php

namespace App\Domain\DayPlan;

use App\Domain\Time\Capacity;
use App\Enums\DayPlanItemStatus;
use App\Models\ActiveTimer;
use App\Models\DayPlan;
use App\Models\DayPlanItem;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * «Mi día» (`/dia`, docs/PLAN-CARGAS.md §4.3): las líneas de un día de quien mira, con sus cifras
 * (jornada, previsto, imputado y hechas), las pendientes de días anteriores que aún se pueden cerrar
 * («Tienes 3 pendientes del lunes», solo en hoy) y lo que se puede hacer ese día.
 *
 * Reglas de cálculo (§6.1): previsto = suma de las horas previstas de las líneas no pasadas a otro
 * día; imputado del día = todas las entradas de la persona ese día (también las que no tienen
 * línea); cumplimiento = hechas / líneas del día (las pasadas y las no hechas no cuentan como hechas).
 * Consultas acotadas: no crecen con el número de líneas.
 */
final class MyDay
{
    public function __construct(
        private readonly Capacity $capacity,
        private readonly DayPlanCalendar $calendar,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user, CarbonImmutable $date): array
    {
        $today = DayPlanCalendar::today();
        $day = $date->toDateString();
        $plan = DayPlan::query()->where('user_id', $user->id)->where('date', $day)->first(['id', 'note', 'published_at']);
        $items = DayPlanItem::query()
            ->where('user_id', $user->id)
            ->where('date', $day)
            ->with(DayPlanPresenter::WITH)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $ids = array_values($items->modelKeys());
        $logged = DayPlanPresenter::loggedByItem($ids);
        $comments = DayPlanPresenter::commentsByItem($ids);
        $running = ActiveTimer::query()->whereKey($user->id)->value('day_plan_item_id');
        $runningId = $running === null ? null : (int) $running;

        $lines = array_values($items->map(fn (DayPlanItem $item): array => DayPlanPresenter::line($item, true, $logged, $runningId, $comments, $user))->all());
        $counted = $items->reject(fn (DayPlanItem $item): bool => $item->status === DayPlanItemStatus::Carried);

        return [
            'date' => $day,
            'today' => $today->toDateString(),
            'horizon_end' => DayPlanCalendar::horizonEnd($today)->toDateString(),
            'deadline' => DayPlanCalendar::deadlineTime(),
            'can' => [
                'write' => DayPlanCalendar::writable($day, $today),
                'close' => $this->calendar->closable($user, $day, $today),
            ],
            'plan' => [
                'note' => $plan?->note,
                'published_at' => $plan?->published_at?->toIso8601ZuluString(),
            ],
            'items' => $lines,
            'summary' => [
                'capacity_minutes' => $this->capacity->onDate($user, $date),
                'planned_minutes' => (int) $counted->sum(fn (DayPlanItem $item): int => (int) $item->planned_minutes),
                'logged_minutes' => (int) TimeEntry::query()->where('user_id', $user->id)->where('date', $day)->sum('minutes'),
                'done' => $items->where('status', DayPlanItemStatus::Done)->count(),
                'total' => $items->count(),
                // «Imputar lo previsto de N líneas hechas sin horas» (D-254).
                'loggable' => $items->filter(fn (DayPlanItem $item): bool => $item->status === DayPlanItemStatus::Done
                    && $item->task_id !== null
                    && $item->planned_minutes !== null
                    && ($logged[$item->id] ?? 0) === 0)->count(),
            ],
            // Las pendientes de días anteriores, solo al mirar hoy (P3: «Pasar a hoy» con un clic).
            'pending' => $day === $today->toDateString() ? $this->pending($user, $today) : [],
            'running_item_id' => $runningId,
        ];
    }

    /**
     * Pendientes de los días anteriores que aún se pueden cerrar, del más antiguo al más reciente.
     *
     * @return list<array{id: int, date: string, text: string, carry_count: int, client: string|null, project: string|null}>
     */
    public function pending(User $user, CarbonImmutable $today): array
    {
        $from = $this->calendar->closableFrom($user, $today)->toDateString();

        if ($from >= $today->toDateString()) {
            return [];
        }

        return array_values(DayPlanItem::query()
            ->where('user_id', $user->id)
            ->where('status', DayPlanItemStatus::Pending->value)
            ->where('date', '>=', $from)
            ->where('date', '<', $today->toDateString())
            ->with(['client:id,name', 'project:id,code'])
            ->orderBy('date')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (DayPlanItem $item): array => [
                'id' => $item->id,
                'date' => $item->date->toDateString(),
                'text' => $item->text,
                'carry_count' => $item->carry_count,
                'client' => $item->client?->name,
                'project' => $item->project?->code,
            ])
            ->all());
    }
}
