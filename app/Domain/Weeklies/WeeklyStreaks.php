<?php

namespace App\Domain\Weeklies;

use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklyExemption;
use App\Models\WeeklySubmission;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Racha y estadísticas de envío de una persona (F-028, F-031 y F-099) con StreakCalculator: reúne
 * sus semanas (todas las de la weekly), sus envíos y sus exenciones. La semana activa usa la foto al
 * vuelo (ausencias que cubren el plazo); las cerradas, la congelada al cerrar.
 *
 * Cuatro consultas como mucho más la foto de la semana activa (3).
 */
final class WeeklyStreaks
{
    public function __construct(
        private readonly WeeklyEligibility $eligibility,
        private readonly StreakCalculator $calculator = new StreakCalculator,
    ) {}

    /**
     * @return array{submitted: int, on_time: int, streak: int}
     */
    public function summary(User $user, ?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());

        return $this->calculator->summary($this->weeks($user), $now);
    }

    /**
     * @return list<StreakWeek>
     */
    public function weeks(User $user): array
    {
        return array_column($this->rows($user), 'week');
    }

    /** Semanas del mapa de constancia (`ws:ProfileView.tsx:87-100`). */
    public const int CONSISTENCY_WEEKS = 12;

    /**
     * Mapa «Constancia (últimas 12 semanas)» (10.9b, D-233; `ws:ProfileView.tsx` y
     * `ws:TeamView.tsx:847-858`): una casilla por semana, de la más antigua a la más reciente, solo
     * desde el alta:
     * - on_time (verde), late (naranja), missed (rojo: sin enviar con el plazo pasado),
     * - exempt y pending (gris: exenta, o la semana activa aún en plazo).
     *
     * @return list<array{cycle_id: int, number: string, label: string, state: string}>
     */
    public function consistency(User $user, ?CarbonInterface $now = null): array
    {
        return $this->consistencyOf($this->rows($user, self::CONSISTENCY_WEEKS), $now);
    }

    /**
     * La racha y el mapa de constancia con las mismas consultas (la ficha de persona y el perfil).
     *
     * @return array{summary: array{submitted: int, on_time: int, streak: int}, consistency: list<array{cycle_id: int, number: string, label: string, state: string}>}
     */
    public function overview(User $user, ?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());
        $rows = $this->rows($user);

        return [
            'summary' => $this->calculator->summary(array_column($rows, 'week'), $now),
            'consistency' => $this->consistencyOf(array_slice($rows, 0, self::CONSISTENCY_WEEKS), $now),
        ];
    }

    /**
     * @param  list<array{cycle: WeeklyCycle, week: StreakWeek}>  $rows  de la más reciente a la más antigua
     * @return list<array{cycle_id: int, number: string, label: string, state: string}>
     */
    private function consistencyOf(array $rows, ?CarbonInterface $now): array
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());
        $timing = new WeeklyTiming;
        $cells = [];

        foreach ($rows as ['cycle' => $cycle, 'week' => $week]) {
            if (! $week->required) {
                continue;
            }

            $state = match (true) {
                $week->exempt => 'exempt',
                $week->submittedAt !== null => $timing->isOnTime($week->submittedAt, $week->deadlineDate->toDateString()) ? 'on_time' : 'late',
                $cycle->isActive() && $now->lessThanOrEqualTo($timing->deadlineEnd($week->deadlineDate->toDateString())) => 'pending',
                default => 'missed',
            };

            $cells[] = ['cycle_id' => $cycle->id, 'number' => $cycle->number, 'label' => $cycle->label, 'state' => $state];
        }

        return array_reverse($cells);
    }

    /**
     * @return list<array{cycle: WeeklyCycle, week: StreakWeek}>
     */
    private function rows(User $user, ?int $limit = null): array
    {
        $cycles = WeeklyCycle::query()
            ->orderByDesc('start_date')
            ->when($limit !== null, fn ($query) => $query->limit((int) $limit))
            ->get(['id', 'number', 'label', 'start_date', 'end_date', 'deadline_date', 'status', 'expected_user_ids']);

        if ($cycles->isEmpty()) {
            return [];
        }

        $submitted = WeeklySubmission::query()
            ->where('user_id', $user->id)
            ->whereNotNull('submitted_at')
            ->pluck('submitted_at', 'weekly_cycle_id')
            ->map(fn ($at): CarbonImmutable => CarbonImmutable::parse($at))
            ->all();

        $exemptions = WeeklyExemption::query()
            ->where('user_id', $user->id)
            ->get(['weekly_cycle_id', 'reason'])
            ->mapWithKeys(fn (WeeklyExemption $row): array => [$row->weekly_cycle_id => $row->reason])
            ->all();

        $createdAt = $user->created_at === null ? null : CarbonImmutable::instance($user->created_at);
        $weeks = [];

        foreach ($cycles as $cycle) {
            $weekEnd = $this->eligibility->weekEnd($cycle);

            if ($cycle->isActive()) {
                $roster = $this->eligibility->rosterForUser($cycle, $user);
                $required = $roster->participates($user->id);
                $exempt = $roster->isExempt($user->id);
            } else {
                $reason = $exemptions[$cycle->id] ?? null;
                $exempt = $reason !== null && $reason->exempts();
                $required = $cycle->expected_user_ids !== null
                    ? in_array($user->id, array_map(intval(...), $cycle->expected_user_ids), true) || $exempt
                    : ($createdAt === null || $createdAt->lessThanOrEqualTo($weekEnd));
            }

            $weeks[] = ['cycle' => $cycle, 'week' => new StreakWeek(
                endDate: CarbonImmutable::parse($cycle->end_date->toDateString(), WeeklyCalendar::TIMEZONE),
                deadlineDate: CarbonImmutable::parse($cycle->deadline_date->toDateString(), WeeklyCalendar::TIMEZONE),
                status: $cycle->status,
                exempt: $exempt,
                submittedAt: $submitted[$cycle->id] ?? null,
                required: $required || isset($submitted[$cycle->id]),
            )];
        }

        return $weeks;
    }
}
