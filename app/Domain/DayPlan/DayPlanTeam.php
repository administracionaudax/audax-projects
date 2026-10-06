<?php

namespace App\Domain\DayPlan;

use App\Domain\Time\Capacity;
use App\Domain\Weeklies\WeeklyAway;
use App\Enums\AbsenceType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * La plantilla del plan del día y cómo es cada día de cada persona (docs/PLAN-CARGAS.md §4.4 y
 * §6.1.6; D-251 y D-252), para «Equipo hoy», la semana y el recordatorio:
 *
 * - **Personas:** activas, de plantilla (admin, responsables y empleados), sin colaboradores
 *   externos, por nombre; con un departamento, solo las suyas.
 * - **Día sin jornada:** capacidad 0 en `Capacity` (fin de semana según su horario, festivo o
 *   ausencia aprobada de día completo) o con «Estoy fuera» de la Weekly (D-228). Nunca cuenta como
 *   «sin plan» y nunca lleva recordatorio.
 * - **Estado** de un día: `plan` (tiene líneas, aunque no trabaje), `holiday`, `away` (ausencia o
 *   «Estoy fuera»), `off` (no trabaja ese día), `future` (un día que viene sin líneas), `not_yet`
 *   (hoy, antes de la hora límite) y `no_plan` (día con jornada, ya pasada la hora límite y sin
 *   líneas).
 */
final class DayPlanTeam
{
    public const array STATES = ['plan', 'holiday', 'away', 'off', 'future', 'not_yet', 'no_plan'];

    public function __construct(private readonly Capacity $capacity) {}

    /**
     * @return Collection<int, User>
     */
    public function people(?int $departmentId = null): Collection
    {
        return User::query()
            ->active()
            ->role(User::WEEKLY_ROLES)
            ->withoutCollaborators()
            ->when($departmentId !== null, fn ($query) => $query->where('department_id', $departmentId))
            ->with('department:id,name')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * Cada día de cada persona: su jornada (capacidad), el festivo, la ausencia y si está fuera. Una
     * consulta de horarios, una de festivos y una de ausencias en total (Capacity::detailsForRanges).
     *
     * @param  Collection<int, User>  $people
     * @return array<int, array<string, array{capacity: int, holiday: string|null, absence: string|null, away: bool}>> persona → fecha → día
     */
    public function days(Collection $people, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($people->isEmpty()) {
            return [];
        }

        $details = $this->capacity->detailsForRanges(array_values($people->map(fn (User $user): array => [
            'user_id' => $user->id,
            'from' => $from,
            'to' => $to,
        ])->all()));

        $days = [];

        foreach ($people->values() as $index => $user) {
            foreach ($details[$index] ?? [] as $date => $detail) {
                $days[$user->id][$date] = [
                    'capacity' => $detail['minutes'],
                    'holiday' => $detail['holiday'],
                    'absence' => $detail['absence'] !== null && $detail['absence']['partial_minutes'] === null ? $detail['absence']['type'] : null,
                    'away' => WeeklyAway::coversDay($user, CarbonImmutable::parse((string) $date)),
                ];
            }
        }

        return $days;
    }

    /** ¿Trabaja ese día? (con jornada y sin «Estoy fuera»). */
    public static function works(array $day): bool
    {
        return $day['capacity'] > 0 && ! $day['away'];
    }

    /**
     * @param  array{capacity: int, holiday: string|null, absence: string|null, away: bool}  $day
     */
    public static function state(array $day, bool $hasLines, string $date, string $today, bool $pastDeadline): string
    {
        return match (true) {
            $hasLines => 'plan',
            $day['capacity'] === 0 && $day['holiday'] !== null => 'holiday',
            ($day['capacity'] === 0 && $day['absence'] !== null) || $day['away'] => 'away',
            $day['capacity'] === 0 => 'off',
            $date > $today => 'future',
            $date === $today && ! $pastDeadline => 'not_yet',
            default => 'no_plan',
        };
    }

    /**
     * El motivo de un día sin jornada: el festivo para todos; el tipo de ausencia (o «Estoy fuera»)
     * solo para quien puede verlo (D-088).
     *
     * @param  array{capacity: int, holiday: string|null, absence: string|null, away: bool}  $day
     */
    public static function reason(array $day, string $state, User $viewer, User $person): ?string
    {
        if ($state === 'holiday') {
            return $day['holiday'];
        }

        if ($state !== 'away' && ! ($state === 'plan' && ($day['absence'] !== null || $day['away']))) {
            return null;
        }

        if (! $viewer->canSeeAbsencesOf($person)) {
            return null;
        }

        if ($day['absence'] !== null) {
            return AbsenceType::tryFrom($day['absence'])?->label();
        }

        return $person->weekly_away_reason?->label();
    }
}
