<?php

namespace App\Domain\Weeklies;

use App\Enums\WeeklyExemptionReason;
use App\Http\Resources\Weeklies\WeeklyCycleResource;
use App\Models\WeeklyCycle;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Datos de la página de las Weeklies (F-036, F-039, F-064 a F-068):
 * - la semana activa y la última cerrada, destacadas, con el estado del equipo (WeeklyTeamStatus),
 * - el histórico: cada semana con su estado (WeeklyTiming::cycleProgress), su participación
 *   (enviadas de las que debían) y si tiene informe.
 *
 * El histórico no calcula la foto de cada semana (sería una consulta por semana): las cerradas usan
 * la foto congelada (expected_user_ids) y la activa, el estado del equipo ya calculado.
 */
final class WeeklyOverview
{
    public const int HISTORY_LIMIT = 104;

    public function __construct(
        private readonly WeeklyTeamStatus $team,
        private readonly WeeklyTiming $timing = new WeeklyTiming,
    ) {}

    /**
     * @return array{
     *     active: array<string, mixed>|null,
     *     latest_closed: array<string, mixed>|null,
     *     cycles: list<array<string, mixed>>
     * }
     */
    public function build(?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());

        $cycles = WeeklyCycle::query()
            ->withCount([
                'submissions' => fn (Builder $query) => $query->whereNotNull('submitted_at'),
                'exemptions' => fn (Builder $query) => $query->whereIn('reason', [WeeklyExemptionReason::Absence->value, WeeklyExemptionReason::Manual->value, WeeklyExemptionReason::Away->value]),
            ])
            ->orderByDesc('start_date')
            ->limit(self::HISTORY_LIMIT)
            ->get();

        $active = $cycles->first(fn (WeeklyCycle $cycle): bool => $cycle->isActive());
        $latestClosed = $cycles->first(fn (WeeklyCycle $cycle): bool => $cycle->isClosed());

        $teams = [];

        foreach ([$active, $latestClosed] as $cycle) {
            if ($cycle !== null) {
                $teams[$cycle->id] = $this->team->for($cycle, $now);
            }
        }

        $rows = array_values($cycles->map(fn (WeeklyCycle $cycle): array => $this->row($cycle, $teams[$cycle->id] ?? null, $now))->all());
        $byId = array_column($rows, null, 'id');

        return [
            'active' => $active === null ? null : [...$byId[$active->id], 'team' => $teams[$active->id]],
            'latest_closed' => $latestClosed === null ? null : [...$byId[$latestClosed->id], 'team' => $teams[$latestClosed->id]],
            'cycles' => $rows,
        ];
    }

    /**
     * @param  array{counts: array{submitted: int, expected: int, exempt: int, pending: int}}|null  $team
     * @return array<string, mixed>
     */
    private function row(WeeklyCycle $cycle, ?array $team, CarbonImmutable $now): array
    {
        if ($team !== null) {
            $participation = [
                'submitted' => $team['counts']['submitted'],
                'expected' => $team['counts']['expected'],
                'exempt' => $team['counts']['exempt'],
            ];
        } else {
            $submitted = (int) $cycle->getAttribute('submissions_count');
            $expected = $cycle->expected_user_ids !== null ? count($cycle->expected_user_ids) : $submitted;
            $participation = [
                'submitted' => min($submitted, max($expected, $submitted)),
                'expected' => max($expected, 0),
                'exempt' => (int) $cycle->getAttribute('exemptions_count'),
            ];
        }

        return [
            ...(new WeeklyCycleResource($cycle))->resolve(),
            'progress' => $this->timing->cycleProgress($cycle->status, $cycle->deadline_date, $participation['submitted'], $participation['expected'], $now)->value,
            'participation' => $participation,
        ];
    }
}
