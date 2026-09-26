<?php

namespace App\Domain\HourBanks;

use App\Models\HourBank;
use App\Models\TaskType;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Desgloses del detalle de una bolsa (SPEC §8, UI): consumo por semana ISO (dentro de la bolsa
 * frente a exceso), por persona y por tipo de tarea. Consultas agregadas en SQL (nunca fila a
 * fila); la agrupación por semana se hace sobre los totales por día, igual en SQLite y PostgreSQL.
 */
final class HourBankBreakdown
{
    /** Límite de semanas de la gráfica (dos años). */
    public const int MAX_WEEKS = 104;

    /**
     * Semanas (lunes a domingo) desde la primera con horas hasta la última, sin huecos.
     *
     * @return list<array{week: string, week_start: string, in_bank_minutes: int, overage_minutes: int}>
     */
    public function weekly(HourBank $bank): array
    {
        $days = TimeEntry::query()
            ->where('hour_bank_id', $bank->id)
            ->groupBy('date')
            ->orderBy('date')
            ->selectRaw('date, SUM(minutes) AS minutes, SUM(overage_minutes) AS overage')
            ->toBase()
            ->get();

        if ($days->isEmpty()) {
            return [];
        }

        /** @var array<string, array{in_bank: int, overage: int}> $weeks */
        $weeks = [];

        foreach ($days as $day) {
            $monday = CarbonImmutable::parse(substr((string) $day->date, 0, 10))->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
            $overage = (int) $day->overage;
            $weeks[$monday] ??= ['in_bank' => 0, 'overage' => 0];
            $weeks[$monday]['in_bank'] += (int) $day->minutes - $overage;
            $weeks[$monday]['overage'] += $overage;
        }

        $first = CarbonImmutable::parse(array_key_first($weeks));
        $last = CarbonImmutable::parse(array_key_last($weeks));

        if ($first->diffInWeeks($last) >= self::MAX_WEEKS) {
            $first = $last->subWeeks(self::MAX_WEEKS - 1);
        }

        $series = [];
        for ($monday = $first; $monday->lessThanOrEqualTo($last); $monday = $monday->addWeek()) {
            $key = $monday->toDateString();
            $series[] = [
                'week' => sprintf('%d-W%02d', $monday->isoWeekYear, $monday->isoWeek),
                'week_start' => $key,
                'in_bank_minutes' => $weeks[$key]['in_bank'] ?? 0,
                'overage_minutes' => $weeks[$key]['overage'] ?? 0,
            ];
        }

        return $series;
    }

    /**
     * Por persona, solo con las entradas que $viewer puede ver (D-021): un responsable ve las de
     * su equipo; un gestor del proyecto y un admin, todas.
     *
     * @return list<array{user: User, minutes: int, overage_minutes: int}>
     */
    public function byPerson(HourBank $bank, User $viewer): array
    {
        $rows = TimeEntry::query()
            ->visibleTo($viewer)
            ->where('time_entries.hour_bank_id', $bank->id)
            ->groupBy('time_entries.user_id')
            ->selectRaw('time_entries.user_id AS user_id, SUM(time_entries.minutes) AS minutes, SUM(time_entries.overage_minutes) AS overage')
            ->orderByRaw('SUM(time_entries.minutes) DESC')
            ->toBase()
            ->get();

        $users = User::query()
            ->whereKey($rows->pluck('user_id')->map(fn ($id): int => (int) $id)->all())
            ->get(['id', 'name', 'avatar_path', 'department_id', 'is_active'])
            ->keyBy('id');

        $result = [];
        foreach ($rows as $row) {
            $user = $users->get((int) $row->user_id);

            if ($user !== null) {
                $result[] = [
                    'user' => $user,
                    'minutes' => (int) $row->minutes,
                    'overage_minutes' => (int) $row->overage,
                ];
            }
        }

        return $result;
    }

    /**
     * Por tipo de tarea (agregado, sin datos de personas). «Sin tipo» va con type null.
     *
     * @return list<array{type: TaskType|null, minutes: int, overage_minutes: int}>
     */
    public function byType(HourBank $bank): array
    {
        $rows = TimeEntry::query()
            ->join('tasks', 'tasks.id', '=', 'time_entries.task_id')
            ->where('time_entries.hour_bank_id', $bank->id)
            ->groupBy('tasks.task_type_id')
            ->selectRaw('tasks.task_type_id AS task_type_id, SUM(time_entries.minutes) AS minutes, SUM(time_entries.overage_minutes) AS overage')
            ->orderByRaw('SUM(time_entries.minutes) DESC')
            ->toBase()
            ->get();

        $types = TaskType::query()
            ->withTrashed()
            ->whereKey($rows->pluck('task_type_id')->filter()->map(fn ($id): int => (int) $id)->all())
            ->get()
            ->keyBy('id');

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'type' => $row->task_type_id !== null ? $types->get((int) $row->task_type_id) : null,
                'minutes' => (int) $row->minutes,
                'overage_minutes' => (int) $row->overage,
            ];
        }

        return $result;
    }

    /**
     * Entradas de la bolsa que $viewer puede ver (D-021), las más recientes primero.
     *
     * @return Builder<TimeEntry>
     */
    public function entries(HourBank $bank, User $viewer): Builder
    {
        return TimeEntry::query()
            ->visibleTo($viewer)
            ->where('time_entries.hour_bank_id', $bank->id)
            ->with([
                'user:id,name,avatar_path,department_id,is_active',
                'task' => fn ($query) => $query->select(['id', 'title', 'project_id']),
                'project' => fn ($query) => $query->select(['id', 'code', 'name', 'color']),
            ])
            ->orderByDesc('time_entries.date')
            ->orderByDesc('time_entries.id');
    }
}
