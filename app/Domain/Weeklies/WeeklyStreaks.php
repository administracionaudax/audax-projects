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
        $cycles = WeeklyCycle::query()
            ->orderByDesc('start_date')
            ->get(['id', 'start_date', 'end_date', 'deadline_date', 'status', 'expected_user_ids']);

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

            $weeks[] = new StreakWeek(
                endDate: CarbonImmutable::parse($cycle->end_date->toDateString(), WeeklyCalendar::TIMEZONE),
                deadlineDate: CarbonImmutable::parse($cycle->deadline_date->toDateString(), WeeklyCalendar::TIMEZONE),
                status: $cycle->status,
                exempt: $exempt,
                submittedAt: $submitted[$cycle->id] ?? null,
                required: $required || isset($submitted[$cycle->id]),
            );
        }

        return $weeks;
    }
}
