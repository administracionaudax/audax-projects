<?php

namespace App\Domain\Weeklies;

use App\Enums\WeeklyExemptionReason;
use App\Enums\WeeklyPersonStatus;
use App\Http\Resources\UserSummaryResource;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklyExemption;
use App\Models\WeeklySubmission;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Estado del equipo en una semana (F-036, F-039 y F-067): quién ha enviado, quién falta y quién está
 * exento, con el estado de cada persona (WeeklyTiming::personStatus) y los recuentos.
 *
 * Personas: las que participan esa semana (WeeklyEligibility) y, además, quien envió aunque ya no
 * participe (p. ej. desactivada después). Orden: enviadas, pendientes y exentas; dentro, por nombre.
 * Consultas acotadas: la foto (3), envíos, exenciones y personas.
 */
final class WeeklyTeamStatus
{
    public function __construct(
        private readonly WeeklyEligibility $eligibility,
        private readonly WeeklyTiming $timing = new WeeklyTiming,
    ) {}

    /**
     * @return array{
     *     members: list<array{user: array<string, mixed>, status: string, submitted_at: string|null, exemption_reason: string|null, exemption_id: int|null}>,
     *     counts: array{submitted: int, expected: int, exempt: int, pending: int}
     * }
     */
    public function for(WeeklyCycle $cycle, ?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());
        $roster = $this->eligibility->rosterFor($cycle);

        /** @var array<int, CarbonImmutable> $submitted */
        $submitted = WeeklySubmission::query()
            ->where('weekly_cycle_id', $cycle->id)
            ->whereNotNull('submitted_at')
            ->get(['user_id', 'submitted_at'])
            ->mapWithKeys(fn (WeeklySubmission $row): array => [$row->user_id => CarbonImmutable::instance($row->submitted_at)])
            ->all();

        /** @var array<int, int> $exemptionIds */
        $exemptionIds = WeeklyExemption::query()
            ->where('weekly_cycle_id', $cycle->id)
            ->pluck('id', 'user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $ids = array_values(array_unique([...$roster->participants(), ...array_keys($submitted)]));
        $users = User::query()->whereKey($ids)->orderBy('name')->get();

        $rank = [
            WeeklyPersonStatus::Submitted->value => 0,
            WeeklyPersonStatus::SubmittedLate->value => 0,
            WeeklyPersonStatus::Overdue->value => 1,
            WeeklyPersonStatus::Pending->value => 1,
            WeeklyPersonStatus::Upcoming->value => 1,
            WeeklyPersonStatus::Missed->value => 1,
            WeeklyPersonStatus::Exempt->value => 2,
            WeeklyPersonStatus::NotRequired->value => 3,
        ];

        $members = [];
        $counts = ['submitted' => 0, 'expected' => count($roster->expected()), 'exempt' => 0, 'pending' => 0];

        foreach ($users as $user) {
            $exempt = $roster->isExempt($user->id);
            $status = $this->timing->personStatus(
                $cycle->status,
                $cycle->deadline_date,
                $submitted[$user->id] ?? null,
                $exempt,
                $roster->participates($user->id),
                $now,
            );

            match (true) {
                $exempt => $counts['exempt']++,
                in_array($status, [WeeklyPersonStatus::Submitted, WeeklyPersonStatus::SubmittedLate], true) => $counts['submitted']++,
                $status !== WeeklyPersonStatus::NotRequired => $counts['pending']++,
                default => null,
            };

            $reason = $roster->reasonFor($user->id);

            $members[] = [
                'user' => (new UserSummaryResource($user))->resolve(),
                'status' => $status->value,
                'submitted_at' => isset($submitted[$user->id]) ? $submitted[$user->id]->toIso8601String() : null,
                'exemption_reason' => $reason === WeeklyExemptionReason::Waived ? null : $reason?->value,
                'exemption_id' => $exempt ? ($exemptionIds[$user->id] ?? null) : null,
            ];
        }

        usort($members, fn (array $a, array $b): int => [$rank[$a['status']], mb_strtolower((string) $a['user']['name'])] <=> [$rank[$b['status']], mb_strtolower((string) $b['user']['name'])]);

        return ['members' => $members, 'counts' => $counts];
    }
}
