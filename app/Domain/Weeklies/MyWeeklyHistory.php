<?php

namespace App\Domain\Weeklies;

use App\Enums\WeeklyExemptionReason;
use App\Http\Resources\Weeklies\WeeklyCycleResource;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklyExemption;
use App\Models\WeeklySubmission;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * «Mis weeklies» (F-042 y F-043): las semanas que me tocaban (o en las que envié), de la más reciente
 * a la más antigua, con mi estado en cada una (Pendiente, Enviado, Enviado con retraso,
 * Próximamente, Con retraso, No enviada, Exento) y cuántos clientes reporté. No salen las semanas
 * anteriores a mi alta. Cuatro consultas más la foto de la semana activa.
 */
final class MyWeeklyHistory
{
    public const int LIMIT = 104;

    public function __construct(
        private readonly WeeklyEligibility $eligibility,
        private readonly WeeklyTiming $timing = new WeeklyTiming,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function for(User $user, ?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());
        $cycles = WeeklyCycle::query()->orderByDesc('start_date')->limit(self::LIMIT)->get();

        $submissions = WeeklySubmission::query()
            ->withCount('entries')
            ->where('user_id', $user->id)
            ->whereIn('weekly_cycle_id', $cycles->pluck('id'))
            ->get()
            ->keyBy('weekly_cycle_id');

        $exemptions = WeeklyExemption::query()
            ->where('user_id', $user->id)
            ->whereIn('weekly_cycle_id', $cycles->pluck('id'))
            ->get(['weekly_cycle_id', 'reason'])
            ->mapWithKeys(fn (WeeklyExemption $row): array => [$row->weekly_cycle_id => $row->reason])
            ->all();

        $createdAt = $user->created_at === null ? null : CarbonImmutable::instance($user->created_at);
        $rows = [];

        foreach ($cycles as $cycle) {
            /** @var WeeklySubmission|null $submission */
            $submission = $submissions->get($cycle->id);
            $submittedAt = $submission?->submitted_at === null ? null : CarbonImmutable::instance($submission->submitted_at);

            if ($cycle->isActive()) {
                $roster = $this->eligibility->rosterForUser($cycle, $user);
                $required = $roster->participates($user->id);
                $reason = $roster->reasonFor($user->id);
            } else {
                $reason = $exemptions[$cycle->id] ?? null;
                $reason = $reason !== null && $reason->exempts() ? $reason : null;
                $required = $cycle->expected_user_ids !== null
                    ? in_array($user->id, array_map(intval(...), $cycle->expected_user_ids), true) || $reason !== null
                    : ($createdAt === null || $createdAt->lessThanOrEqualTo($this->eligibility->weekEnd($cycle)));
            }

            if (! $required && $submission === null) {
                continue;
            }

            $exempt = $reason !== null && $reason->exempts();
            $status = $this->timing->personStatus($cycle->status, $cycle->deadline_date, $submittedAt, $exempt, $required, $now);

            $rows[] = [
                'cycle' => (new WeeklyCycleResource($cycle))->resolve(),
                'status' => $status->value,
                'is_upcoming' => $cycle->isActive() && $this->timing->isUpcoming($cycle->deadline_date, $now),
                'submitted_at' => $submittedAt?->toIso8601String(),
                'has_draft' => $submission !== null && $submittedAt === null && (int) $submission->getAttribute('entries_count') > 0,
                'entries_count' => (int) ($submission?->getAttribute('entries_count') ?? 0),
                'exemption_reason' => $exempt && $reason !== WeeklyExemptionReason::Waived ? $reason->value : null,
            ];
        }

        return $rows;
    }
}
