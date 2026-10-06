<?php

namespace App\Domain\Weeklies;

use App\Enums\AbsenceStatus;
use App\Enums\Role;
use App\Enums\WeeklyExemptionReason;
use App\Models\Absence;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklyExemption;
use App\Models\WeeklySubmission;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Quién debe enviar la weekly de una semana (D-147 y D-150, F-071, F-092, F-097 y F-098).
 *
 * Semana ACTIVA (se calcula al vuelo, así un cambio de ausencias se nota al momento, F-098):
 * - participan los internos ACTIVOS de plantilla (admin, responsables y empleados; nunca los
 *   colaboradores externos, D-134, ni los clientes) dados de alta antes del final del viernes,
 * - están exentos quienes tienen una ausencia APROBADA de día completo que cubre el día del plazo
 *   (start_date ≤ plazo ≤ end_date), quienes se han marcado «Estoy fuera» hasta el plazo o después
 *   (WeeklyAway, D-228) y quienes tienen una exención manual,
 * - una renuncia (waived, F-053) anula la exención por ausencia.
 *
 * Semana CERRADA (foto congelada con freeze() al cerrar, F-092):
 * - deben enviar los de expected_user_ids y están exentos los de weekly_exemptions (absence o
 *   manual). Si una semana importada no trae la foto, se reconstruye con quien envió, los exentos
 *   guardados y los activos de entonces (aproximación; el importador debería traer la foto).
 *
 * Tres consultas como mucho (personas con su «Estoy fuera», ausencias y exenciones).
 */
final class WeeklyEligibility
{
    public function rosterFor(WeeklyCycle $cycle): WeeklyRoster
    {
        $rows = $this->rows($cycle);

        if ($cycle->isClosed()) {
            return $this->closedRoster($cycle, $rows);
        }

        [$candidates, $away] = $this->candidates($this->weekEnd($cycle), $cycle->deadline_date);
        $absences = $this->coveringAbsences($candidates, $cycle->deadline_date);

        return self::resolve($candidates, $absences, $rows, $away);
    }

    /**
     * La foto de UNA persona (10.2): para la barra lateral, la tarjeta de Inicio y el envío, sin
     * cargar a toda la plantilla. Con la semana cerrada, la foto congelada (como rosterFor()).
     */
    public function rosterForUser(WeeklyCycle $cycle, User $user): WeeklyRoster
    {
        $rows = $this->rows($cycle, $user->id);

        if ($cycle->isClosed()) {
            return $this->closedRoster($cycle, $rows);
        }

        [$candidates, $away] = $this->candidates($this->weekEnd($cycle), $cycle->deadline_date, $user->id);
        $absences = $this->coveringAbsences($candidates, $cycle->deadline_date);

        return self::resolve($candidates, $absences, $rows, $away);
    }

    /**
     * Congela la foto al cerrar (F-092): guarda como exención `absence` a quien le eximía una
     * ausencia (y `away` a quien estaba fuera, D-228) y en expected_user_ids a quien debía enviar. Llamar dentro de la transacción del
     * cierre y ANTES de marcar la semana como cerrada.
     */
    public function freeze(WeeklyCycle $cycle): WeeklyRoster
    {
        $roster = $this->rosterFor($cycle);

        DB::transaction(function () use ($cycle, $roster): void {
            foreach ($roster->exemptions() as $userId => $reason) {
                if ($reason !== WeeklyExemptionReason::Absence && $reason !== WeeklyExemptionReason::Away) {
                    continue;
                }

                WeeklyExemption::query()->firstOrCreate(
                    ['weekly_cycle_id' => $cycle->id, 'user_id' => $userId],
                    ['reason' => $reason, 'absence_id' => $roster->absenceFor($userId)],
                );
            }

            $cycle->forceFill(['expected_user_ids' => $roster->expected()])->save();
        });

        return $roster;
    }

    /**
     * Regla pura (sin base de datos), para la semana activa.
     *
     * @param  list<int>  $candidates  internos activos de plantilla dados de alta a tiempo
     * @param  array<int, int>  $coveringAbsences  persona → ausencia aprobada que cubre el plazo
     * @param  array<int, array{reason: WeeklyExemptionReason, absence_id: int|null}>  $rows  filas de weekly_exemptions
     * @param  array<int, true>  $away  personas con «Estoy fuera» que cubre el plazo (D-228)
     */
    public static function resolve(array $candidates, array $coveringAbsences, array $rows, array $away = []): WeeklyRoster
    {
        $exemptions = [];
        $absenceIds = [];

        foreach ($candidates as $userId) {
            $row = $rows[$userId] ?? null;

            if ($row !== null && $row['reason'] === WeeklyExemptionReason::Waived) {
                continue;
            }

            if ($row !== null) {
                $exemptions[$userId] = $row['reason'];

                if ($row['absence_id'] !== null) {
                    $absenceIds[$userId] = $row['absence_id'];
                }

                continue;
            }

            if (isset($coveringAbsences[$userId])) {
                $exemptions[$userId] = WeeklyExemptionReason::Absence;
                $absenceIds[$userId] = $coveringAbsences[$userId];

                continue;
            }

            if (isset($away[$userId])) {
                $exemptions[$userId] = WeeklyExemptionReason::Away;
            }
        }

        return new WeeklyRoster($candidates, $exemptions, $absenceIds);
    }

    /**
     * Final del viernes (o del día de fin) en Madrid: quien se dio de alta después no cuenta (F-071).
     */
    public function weekEnd(WeeklyCycle $cycle): CarbonImmutable
    {
        return CarbonImmutable::parse($cycle->end_date->toDateString(), WeeklyCalendar::TIMEZONE)->endOfDay();
    }

    /**
     * @param  array<int, array{reason: WeeklyExemptionReason, absence_id: int|null}>  $rows
     */
    private function closedRoster(WeeklyCycle $cycle, array $rows): WeeklyRoster
    {
        $exemptions = [];
        $absenceIds = [];

        foreach ($rows as $userId => $row) {
            if ($row['reason']->exempts()) {
                $exemptions[$userId] = $row['reason'];

                if ($row['absence_id'] !== null) {
                    $absenceIds[$userId] = $row['absence_id'];
                }
            }
        }

        ksort($exemptions);

        if ($cycle->expected_user_ids !== null) {
            $expected = array_map(intval(...), $cycle->expected_user_ids);
        } else {
            $submitted = WeeklySubmission::query()
                ->where('weekly_cycle_id', $cycle->id)
                ->whereNotNull('submitted_at')
                ->pluck('user_id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $expected = array_values(array_unique([...$this->candidateIds($this->weekEnd($cycle)), ...$submitted]));
        }

        $participants = array_values(array_unique([...array_filter($expected, fn (int $id): bool => ! isset($exemptions[$id])), ...array_keys($exemptions)]));
        sort($participants);

        return new WeeklyRoster($participants, $exemptions, $absenceIds);
    }

    /**
     * Los candidatos y, de ellos, quién está fuera el día del plazo (D-228), en una consulta.
     *
     * @return array{0: list<int>, 1: array<int, true>}
     */
    private function candidates(CarbonInterface $weekEnd, CarbonInterface $deadline, ?int $onlyUserId = null): array
    {
        $ids = [];
        $away = [];

        foreach ($this->candidateQuery($weekEnd, $onlyUserId)->get(['id', 'weekly_away_reason', 'weekly_away_since', 'weekly_away_until']) as $user) {
            $ids[] = $user->id;

            if (WeeklyAway::coversDay($user, $deadline)) {
                $away[$user->id] = true;
            }
        }

        return [$ids, $away];
    }

    /**
     * @return list<int>
     */
    private function candidateIds(CarbonInterface $weekEnd, ?int $onlyUserId = null): array
    {
        return array_values($this->candidateQuery($weekEnd, $onlyUserId)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all());
    }

    /**
     * @return Builder<User>
     */
    private function candidateQuery(CarbonInterface $weekEnd, ?int $onlyUserId = null): Builder
    {
        return User::query()
            ->when($onlyUserId !== null, fn (Builder $query) => $query->whereKey($onlyUserId))
            ->where('is_active', true)
            ->where('created_at', '<=', $weekEnd->utc())
            ->whereHas('roles', fn (Builder $roles) => $roles->whereIn('name', User::WEEKLY_ROLES))
            ->whereDoesntHave('roles', fn (Builder $roles) => $roles->whereIn('name', [Role::Collaborator->value, Role::Client->value]))
            ->orderBy('id');
    }

    /**
     * Ausencias aprobadas de día completo que cubren el día del plazo, una por persona.
     *
     * @param  list<int>  $userIds
     * @return array<int, int>
     */
    private function coveringAbsences(array $userIds, CarbonInterface $deadline): array
    {
        if ($userIds === []) {
            return [];
        }

        $day = $deadline->toDateString();

        return Absence::query()
            ->whereIn('user_id', $userIds)
            ->where('status', AbsenceStatus::Approved->value)
            ->whereNull('partial_minutes')
            ->whereDate('start_date', '<=', $day)
            ->whereDate('end_date', '>=', $day)
            ->orderBy('id')
            ->get(['id', 'user_id'])
            ->mapWithKeys(fn (Absence $absence): array => [$absence->user_id => $absence->id])
            ->all();
    }

    /**
     * @return array<int, array{reason: WeeklyExemptionReason, absence_id: int|null}>
     */
    private function rows(WeeklyCycle $cycle, ?int $onlyUserId = null): array
    {
        return WeeklyExemption::query()
            ->where('weekly_cycle_id', $cycle->id)
            ->when($onlyUserId !== null, fn (Builder $query) => $query->where('user_id', $onlyUserId))
            ->get(['user_id', 'reason', 'absence_id'])
            ->mapWithKeys(fn (WeeklyExemption $row): array => [$row->user_id => ['reason' => $row->reason, 'absence_id' => $row->absence_id]])
            ->all();
    }
}
