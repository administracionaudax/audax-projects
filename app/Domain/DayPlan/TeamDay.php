<?php

namespace App\Domain\DayPlan;

use App\Enums\DayPlanItemStatus;
use App\Models\ActiveTimer;
use App\Models\DayPlan;
use App\Models\DayPlanItem;
use App\Models\Department;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * «Equipo hoy» (`/dia/equipo`, docs/PLAN-CARGAS.md §4.2 y §4.3; D-251 y D-252): una fila por persona
 * de la plantilla (por departamento) con su plan del día, si ya lo ha escrito y, a quien puede verlas
 * (ella, su responsable y los admins), sus cifras: hora del plan, jornada, previsto, imputado, hechas,
 * arrastradas y lo que tiene en marcha el temporizador.
 *
 * Consultas acotadas sea cual sea la plantilla: personas, departamentos, capacidad (tres), líneas y
 * sus relaciones, cabeceras, imputado por línea y por persona, temporizadores y comentarios.
 */
final class TeamDay
{
    public function __construct(private readonly DayPlanTeam $team) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $viewer, CarbonImmutable $date, ?int $departmentId): array
    {
        $today = DayPlanCalendar::today()->toDateString();
        $day = $date->toDateString();
        $pastDeadline = DayPlanCalendar::pastDeadline($day);
        $people = $this->team->people($departmentId);
        $ids = $people->modelKeys();
        $days = $this->team->days($people, $date, $date);

        $items = DayPlanItem::query()
            ->whereIn('user_id', $ids)
            ->where('date', $day)
            ->with(DayPlanPresenter::WITH)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->groupBy('user_id');
        $plans = DayPlan::query()->whereIn('user_id', $ids)->where('date', $day)->get(['user_id', 'note', 'published_at', 'reminded_at'])->keyBy('user_id');

        // Cifras y comentarios: solo de quien las puede ver quien mira (D-251).
        $figuresFor = array_values(array_filter($ids, fn (int $id): bool => DayPlanAccess::seesFigures($viewer, $people->find($id) ?? $viewer)));
        $visibleItemIds = array_values($items->toBase()->only($figuresFor)->flatten()->map(fn (DayPlanItem $item): int => $item->id)->all());
        $logged = DayPlanPresenter::loggedByItem($visibleItemIds);
        $comments = DayPlanPresenter::commentsByItem($visibleItemIds);
        $loggedByUser = $figuresFor === [] ? collect() : TimeEntry::query()
            ->whereIn('user_id', $figuresFor)
            ->where('date', $day)
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(minutes) AS total')
            ->pluck('total', 'user_id');
        $timers = $figuresFor === [] || $day !== $today ? collect() : ActiveTimer::query()
            ->whereIn('user_id', $figuresFor)
            ->with(['task' => fn ($query) => $query->withTrashed()->select(['id', 'title'])])
            ->get()
            ->keyBy('user_id');
        $timerItemIds = $timers->pluck('day_plan_item_id')->filter()->values()->all();
        $timerLines = $timerItemIds === [] ? collect() : DayPlanItem::query()->whereKey($timerItemIds)->pluck('text', 'id');

        $rows = [];
        $summary = ['people' => 0, 'with_plan' => 0, 'without_plan' => 0, 'away' => 0, 'figures' => ['done' => 0, 'total' => 0, 'carried' => 0]];
        $allFigures = true;

        foreach ($people as $person) {
            /** @var Collection<int, DayPlanItem> $lines */
            $lines = $items->get($person->id, collect());
            $info = $days[$person->id][$day] ?? ['capacity' => 0, 'holiday' => null, 'absence' => null, 'away' => false];
            $state = DayPlanTeam::state($info, $lines->isNotEmpty(), $day, $today, $pastDeadline);
            $figures = in_array($person->id, $figuresFor, true);
            $timer = $timers->get($person->id);
            $plan = $plans->get($person->id);
            $runningItem = $timer?->day_plan_item_id;

            $rows[] = [
                'user' => self::person($person),
                'state' => $state,
                'reason' => DayPlanTeam::reason($info, $state, $viewer, $person),
                'note' => $plan?->note,
                'items' => array_values($lines->map(fn (DayPlanItem $item): array => DayPlanPresenter::line(
                    $item,
                    $figures,
                    $logged,
                    $runningItem,
                    $figures ? $comments : null,
                    $viewer,
                ))->all()),
                'figures' => $figures ? [
                    'published_at' => $plan?->published_at?->toIso8601ZuluString(),
                    'capacity_minutes' => $info['capacity'],
                    'planned_minutes' => (int) $lines->reject(fn (DayPlanItem $item): bool => $item->status === DayPlanItemStatus::Carried)->sum(fn (DayPlanItem $item): int => (int) $item->planned_minutes),
                    'logged_minutes' => (int) $loggedByUser->get($person->id, 0),
                    'done' => $lines->where('status', DayPlanItemStatus::Done)->count(),
                    'total' => $lines->count(),
                    'carried' => $lines->where('carry_count', '>', 0)->count(),
                    'running' => $timer === null ? null : [
                        'item_id' => $runningItem,
                        'text' => $runningItem !== null ? (string) ($timerLines[$runningItem] ?? $timer->task->title) : $timer->task->title,
                        'started_at' => $timer->started_at->toIso8601ZuluString(),
                    ],
                ] : null,
                'can_comment' => $viewer->id !== $person->id && DayPlanAccess::comments($viewer, $person),
                'can_remind' => $day === $today && in_array($state, ['no_plan', 'not_yet'], true) && DayPlanAccess::reminds($viewer, $person),
                'reminded' => $plan?->reminded_at !== null,
            ];

            $summary['people']++;
            $summary['with_plan'] += $state === 'plan' ? 1 : 0;
            $summary['without_plan'] += $state === 'no_plan' ? 1 : 0;
            $summary['away'] += in_array($state, ['away', 'holiday'], true) ? 1 : 0;

            if ($figures) {
                $summary['figures']['done'] += $lines->where('status', DayPlanItemStatus::Done)->count();
                $summary['figures']['total'] += $lines->count();
                $summary['figures']['carried'] += $lines->where('carry_count', '>', 0)->count();
            } else {
                $allFigures = false;
            }
        }

        if (! $allFigures || $rows === []) {
            $summary['figures'] = null;
        }

        return [
            'date' => $day,
            'today' => $today,
            'deadline' => DayPlanCalendar::deadlineTime(),
            'past_deadline' => $pastDeadline,
            'department' => $departmentId ?? 'all',
            'departments' => self::departments(),
            'rows' => $rows,
            'summary' => $summary,
        ];
    }

    /**
     * @return array{id: int, name: string, avatar: string|null, department: array{id: int, name: string}|null}
     */
    public static function person(User $person): array
    {
        return [
            'id' => $person->id,
            'name' => $person->name,
            'avatar' => $person->avatar_url,
            'department' => $person->department === null ? null : ['id' => $person->department->id, 'name' => $person->department->name],
        ];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public static function departments(): array
    {
        return array_values(Department::query()->orderBy('name')->get(['id', 'name'])->map(fn (Department $department): array => [
            'id' => $department->id,
            'name' => $department->name,
        ])->all());
    }
}
