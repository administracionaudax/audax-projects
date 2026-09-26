<?php

namespace App\Http\Controllers;

use App\Domain\Time\Capacity;
use App\Domain\Time\Week;
use App\Enums\TimesheetStatus;
use App\Http\Resources\Time\HomeTaskResource;
use App\Http\Resources\Time\Plain;
use App\Http\Resources\Time\TimesheetPeriodResource;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\TimesheetPeriod;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inicio: el panel personal (SPEC §5.1). Solo muestra las cosas de quien lo mira (D-021):
 * - sus tareas abiertas vencidas, de hoy y de esta semana (D-037),
 * - sus horas de hoy y de la semana frente a su capacidad,
 * - el estado de su semana (con el comentario si se la han devuelto),
 * - los días laborables sin imputar de las dos últimas semanas.
 * El temporizador activo llega en las props compartidas. El resto de tarjetas llegan en otras fases.
 */
class HomeController extends Controller
{
    /**
     * Días hacia atrás en los que se buscan días laborables sin horas (hasta ayer).
     */
    public const int UNLOGGED_LOOKBACK_DAYS = 14;

    public const int TASKS_LIMIT = 50;

    public function __construct(
        private readonly Capacity $capacity,
    ) {}

    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $today = LocalTime::today();
        $week = Week::current();

        $period = TimesheetPeriod::query()->with('reviewer')->firstOrNew(
            ['user_id' => $user->id, 'week_start' => $week->startString()],
            ['status' => TimesheetStatus::Open],
        );

        return Inertia::render('home', [
            'tasks' => $this->tasks($user, $today, $week),
            'hours' => $this->hours($user, $today, $week),
            'week' => [
                'iso' => $week->iso(),
                'period' => Plain::of(new TimesheetPeriodResource($period)),
            ],
            'unlogged_days' => $this->unloggedDays($user, $today),
        ]);
    }

    /**
     * Abiertas y asignadas a mí (D-037): vencidas (antes de hoy), de hoy (vencen o empiezan hoy) y
     * de esta semana (vencen hasta el domingo).
     *
     * @return array{overdue: list<array<string, mixed>>, today: list<array<string, mixed>>, week: list<array<string, mixed>>}
     */
    private function tasks(User $user, CarbonImmutable $today, Week $week): array
    {
        $todayString = $today->toDateString();

        $tasks = Task::query()
            ->open()
            ->assignedTo($user)
            ->whereHas('project', fn (Builder $project) => $project->notArchived())
            ->where(fn (Builder $when) => $when
                ->where('due_date', '<=', $week->endString())
                ->orWhere('start_date', $todayString))
            ->with(['project:id,code,name,color', 'status:id,name,color,category'])
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date')
            ->orderBy('title')
            ->limit(self::TASKS_LIMIT)
            ->get();

        $groups = ['overdue' => [], 'today' => [], 'week' => []];

        foreach ($tasks as $task) {
            $due = $task->due_date?->toDateString();
            $start = $task->start_date?->toDateString();

            $group = match (true) {
                $due !== null && $due < $todayString => 'overdue',
                $due === $todayString || $start === $todayString => 'today',
                default => 'week',
            };

            $groups[$group][] = Plain::of(new HomeTaskResource($task));
        }

        return $groups;
    }

    /**
     * @return array{today: int, week: int, capacity_today: int, capacity_week: int}
     */
    private function hours(User $user, CarbonImmutable $today, Week $week): array
    {
        $byDay = TimeEntry::query()
            ->where('user_id', $user->id)
            ->between($week->startString(), $week->endString())
            ->selectRaw('time_entries.date as day, SUM(minutes) as total')
            ->groupBy('time_entries.date')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [CarbonImmutable::parse((string) ((array) $row)['day'])->toDateString() => (int) ((array) $row)['total']]);

        $capacity = $this->capacity->forRange($user, $week->start, $week->end());

        return [
            'today' => (int) ($byDay[$today->toDateString()] ?? 0),
            'week' => (int) $byDay->sum(),
            'capacity_today' => $capacity[$today->toDateString()] ?? 0,
            'capacity_week' => array_sum($capacity),
        ];
    }

    /**
     * Días con jornada (capacidad > 0) y sin ninguna hora, desde hace dos semanas hasta ayer (y
     * nunca antes de su alta).
     *
     * @return list<array{date: string, capacity: int, week: string}>
     */
    private function unloggedDays(User $user, CarbonImmutable $today): array
    {
        // Fechas locales sin hora: se comparan y recorren como Y-m-d, nunca como instantes.
        $day = CarbonImmutable::parse($today->toDateString());
        $to = $day->subDay()->toDateString();
        $from = $day->subDays(self::UNLOGGED_LOOKBACK_DAYS)->toDateString();

        if ($user->created_at !== null) {
            $from = max($from, LocalTime::dateOf($user->created_at));
        }

        if ($from > $to) {
            return [];
        }

        $logged = TimeEntry::query()
            ->where('user_id', $user->id)
            ->between($from, $to)
            ->distinct()
            ->pluck('date')
            ->map(fn (mixed $date): string => $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : CarbonImmutable::parse((string) $date)->toDateString())
            ->flip();

        $capacity = $this->capacity->forRange($user, CarbonImmutable::parse($from), CarbonImmutable::parse($to));
        $days = [];

        foreach ($capacity as $date => $minutes) {
            if ($minutes > 0 && ! $logged->has($date)) {
                $days[] = ['date' => $date, 'capacity' => $minutes, 'week' => Week::containing($date)->iso()];
            }
        }

        return $days;
    }
}
