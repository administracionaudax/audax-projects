<?php

namespace App\Domain\People;

use App\Domain\People\Reports\RegisterDataset;
use App\Domain\Time\Week;
use App\Enums\HourType;
use App\Enums\MonthCloseStatus;
use App\Enums\OvertimeDestination;
use App\Models\MonthClose;
use App\Models\OvertimeDecision;
use App\Models\User;
use App\Notifications\People\OvertimeCapReached;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Horas extra (PLAN-FASE-11 §7.2; P6 de §14.1: «sí hay horas extra y se fichan»; D-349; W-047 y
 * W-053). R1 registra todo el exceso del día; aquí se **clasifica**:
 *
 * - Lo decide el responsable de la persona o RR. HH., nunca ella misma: de su exceso, cuánto es
 *   hora extra (art. 35 ET) y cuánto flexibilidad (tiempo que se compensa dentro de la jornada
 *   pactada), y el destino de la extra: **compensar** con descanso (pasa al saldo de horas, 80
 *   minutos por hora, convenio de publicidad) o **pagar**. A tiempo parcial no hay horas extra
 *   (art. 12.4.c ET): son **complementarias** (art. 12.5) y se pagan.
 * - Solo los días ya cerrados con exceso. Cada decisión es una fila nueva sellada; una decisión
 *   nueva del mismo día sustituye a la anterior (se conservan las dos) y su efecto en el saldo se
 *   revierte con un movimiento.
 * - Si el exceso del día cambia después (una corrección aceptada), la decisión queda «por revisar».
 * - Un mes confirmado no se clasifica sin desconfirmarlo (MonthCloser::assertMonthOpen); si el
 *   cierre está pendiente, se regenera.
 * - **Tope de 80 horas al año** (art. 35.2 ET): se cuentan TODAS las horas extra del año natural,
 *   también las compensadas con descanso (la ley permite descontar las compensadas en los 4 meses
 *   siguientes; hasta que la asesoría lo confirme, el aviso llega antes, nunca después). Avisa a
 *   RR. HH. y al responsable al pasar de 60 h y de 80 h; nunca impide registrar lo trabajado.
 */
final class OvertimeService
{
    /** Tope legal de horas extra al año (art. 35.2 ET), en minutos. */
    public const int YEAR_CAP_MINUTES = 80 * 60;

    /** A partir de aquí, aviso preventivo (B-6). */
    public const int YEAR_WARNING_MINUTES = 60 * 60;

    /** Minutos de descanso por cada hora extra compensada (convenio de publicidad, art. 22). */
    public const int REST_MINUTES_PER_HOUR = 80;

    public function __construct(
        private readonly RegisterDataset $dataset,
        private readonly TimeBalanceLedger $ledger,
        private readonly MonthCloser $closer,
        private readonly RegisterHasher $hasher,
    ) {}

    /**
     * Clasifica el exceso de un día.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function decide(User $actor, User $subject, string $date, int $overtimeMinutes, ?OvertimeDestination $destination, ?string $note = null): OvertimeDecision
    {
        if (! PeopleAccess::decidesFor($actor, $subject)) {
            throw new AuthorizationException;
        }

        if ($date >= LocalTime::todayString()) {
            throw ValidationException::withMessages(['date' => __('people.errors.overtime_day_open')]);
        }

        MonthCloser::assertMonthOpen($subject->id, $date);

        $before = $this->yearMinutes($subject->id, (int) substr($date, 0, 4));

        $decision = DB::transaction(function () use ($actor, $subject, $date, $overtimeMinutes, $destination, $note): OvertimeDecision {
            User::query()->whereKey($subject->id)->lockForUpdate()->value('id');

            $line = $this->dataset->build([$subject], $date, $date)[$subject->id]['lines'][$date] ?? null;
            $excess = $line['excess_minutes'] ?? 0;

            if ($excess <= 0) {
                throw ValidationException::withMessages(['date' => __('people.errors.overtime_no_excess')]);
            }

            if ($overtimeMinutes < 0 || $overtimeMinutes > $excess) {
                throw ValidationException::withMessages(['overtime_minutes' => __('people.errors.overtime_range', ['max' => $excess])]);
            }

            $type = $subject->employmentProfile?->part_time === true ? HourType::Complementary : HourType::Overtime;

            if ($overtimeMinutes > 0 && $destination === null) {
                throw ValidationException::withMessages(['destination' => __('people.errors.overtime_destination')]);
            }

            if ($type === HourType::Complementary && $destination === OvertimeDestination::Compensate) {
                throw ValidationException::withMessages(['destination' => __('people.errors.complementary_compensate')]);
            }

            $previous = OvertimeDecision::query()->effective()->where('user_id', $subject->id)->where('date', $date)->lockForUpdate()->first();

            $decision = new OvertimeDecision;
            $decision->forceFill([
                'user_id' => $subject->id,
                'date' => $date,
                'hour_type' => $type,
                'excess_minutes' => $excess,
                'overtime_minutes' => $overtimeMinutes,
                'flex_minutes' => $excess - $overtimeMinutes,
                'destination' => $overtimeMinutes > 0 ? $destination : null,
                'note' => $note === null || trim($note) === '' ? null : trim($note),
                'decided_by' => $actor->id,
                'supersedes_id' => $previous?->id,
                'created_at' => CarbonImmutable::now()->startOfSecond(),
            ]);
            $decision->hash = $this->hasher->overtimeDecision($decision);
            $decision->save();

            if ($previous !== null && $previous->destination === OvertimeDestination::Compensate && $previous->overtime_minutes > 0) {
                $this->ledger->reverse($previous, $actor, $decision);
            }

            if ($decision->destination === OvertimeDestination::Compensate && $decision->overtime_minutes > 0) {
                $this->ledger->credit($decision, $actor);
            }

            return $decision;
        });

        activity('people-register')
            ->causedBy($actor)
            ->performedOn($decision)
            ->event('overtime_decided')
            ->withProperties([
                'user_id' => $subject->id,
                'date' => $date,
                'excess_minutes' => $decision->excess_minutes,
                'overtime_minutes' => $decision->overtime_minutes,
                'hour_type' => $decision->hour_type->value,
                'destination' => $decision->destination?->value,
                'supersedes_id' => $decision->supersedes_id,
            ])
            ->log('overtime.decided');

        $this->closer->refreshAfterChange($subject, $date);
        $this->warnCap($subject, $before, $this->yearMinutes($subject->id, (int) substr($date, 0, 4)));

        return $decision;
    }

    /**
     * Días con exceso por clasificar (o con una decisión que hay que revisar porque el exceso ha
     * cambiado) de las personas que $viewer decide, entre dos días; nunca los del propio $viewer.
     *
     * @return list<array{user: User, date: string, excess_minutes: int, worked_minutes: int, expected_minutes: int, decision: OvertimeDecision|null, stale: bool, part_time: bool, month_confirmed: bool}>
     */
    public function pending(User $viewer, string $from, string $to): array
    {
        $users = array_values(PeopleAccess::teamQuery($viewer)->whereKeyNot($viewer->id)->with('employmentProfile')->orderBy('name')->get()->all());

        if ($users === []) {
            return [];
        }

        $data = $this->dataset->build($users, $from, min($to, LocalTime::today()->subDay()->toDateString()));
        $decisions = $this->dataset->decisions(array_map(fn (User $user): int => $user->id, $users), $from, $to);
        $confirmed = $this->confirmedMonths(array_map(fn (User $user): int => $user->id, $users), $from, $to);
        $pending = [];

        foreach ($data as $userId => $entry) {
            foreach ($entry['lines'] as $date => $line) {
                if ($line['excess_minutes'] <= 0) {
                    continue;
                }

                $decision = $decisions[$userId][$date] ?? null;

                if ($decision !== null && ! $line['decision_stale']) {
                    continue;
                }

                $pending[] = [
                    'user' => $entry['user'],
                    'date' => $date,
                    'excess_minutes' => $line['excess_minutes'],
                    'worked_minutes' => $line['worked_minutes'],
                    'expected_minutes' => $line['expected_minutes'],
                    'decision' => $decision,
                    'stale' => $line['decision_stale'],
                    'part_time' => $entry['user']->employmentProfile?->part_time === true,
                    'month_confirmed' => isset($confirmed[$userId][substr($date, 0, 7)]),
                ];
            }
        }

        usort($pending, fn (array $a, array $b): int => [$a['date'], $a['user']->name] <=> [$b['date'], $b['user']->name]);

        return $pending;
    }

    /**
     * Las decisiones de un periodo de las personas que $viewer ve (o de una persona), las últimas
     * primero.
     *
     * @param  list<int>  $userIds
     * @return list<OvertimeDecision>
     */
    public function decided(array $userIds, string $from, string $to): array
    {
        return array_values(OvertimeDecision::query()
            ->effective()
            ->with(['user:id,name', 'decider:id,name'])
            ->whereIn('user_id', $userIds)
            ->whereBetween('date', [$from, $to])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get()
            ->all());
    }

    /**
     * Horas extra (no complementarias) del año natural de una persona, en minutos.
     */
    public function yearMinutes(int $userId, int $year): int
    {
        return (int) OvertimeDecision::query()
            ->effective()
            ->where('user_id', $userId)
            ->where('hour_type', HourType::Overtime->value)
            ->whereBetween('date', [sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)])
            ->sum('overtime_minutes');
    }

    /**
     * Resumen del año: horas extra (a compensar y a pagar), complementarias, el tope y su nivel.
     *
     * @return array{year: int, overtime_minutes: int, compensate_minutes: int, pay_minutes: int, complementary_minutes: int, cap_minutes: int, remaining_minutes: int, level: string}
     */
    public function yearSummary(User $user, int $year): array
    {
        $rows = OvertimeDecision::query()
            ->effective()
            ->where('user_id', $user->id)
            ->whereBetween('date', [sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)])
            ->get(['hour_type', 'destination', 'overtime_minutes']);

        $overtime = 0;
        $compensate = 0;
        $pay = 0;
        $complementary = 0;

        foreach ($rows as $row) {
            if ($row->hour_type === HourType::Complementary) {
                $complementary += $row->overtime_minutes;

                continue;
            }

            $overtime += $row->overtime_minutes;
            $compensate += $row->destination === OvertimeDestination::Compensate ? $row->overtime_minutes : 0;
            $pay += $row->destination === OvertimeDestination::Pay ? $row->overtime_minutes : 0;
        }

        return [
            'year' => $year,
            'overtime_minutes' => $overtime,
            'compensate_minutes' => $compensate,
            'pay_minutes' => $pay,
            'complementary_minutes' => $complementary,
            'cap_minutes' => self::YEAR_CAP_MINUTES,
            'remaining_minutes' => max(self::YEAR_CAP_MINUTES - $overtime, 0),
            'level' => self::level($overtime),
        ];
    }

    /**
     * Resumen de una semana (art. 35.5 ET y convenio: totalización semanal con copia a la persona):
     * los días con horas extra o complementarias reconocidas y el exceso aún sin clasificar.
     *
     * @return array{week: string, days: list<array{date: string, overtime_minutes: int, complementary_minutes: int, destination: string|null, unclassified_minutes: int}>, overtime_minutes: int, complementary_minutes: int, unclassified_minutes: int}
     */
    public function weekSummary(User $user, Week $week): array
    {
        $from = $week->startString();
        $to = $week->endString();
        $lines = $this->dataset->build([$user], $from, $to)[$user->id]['lines'] ?? [];
        $days = [];
        $totals = ['overtime_minutes' => 0, 'complementary_minutes' => 0, 'unclassified_minutes' => 0];

        foreach ($lines as $date => $line) {
            if ($line['overtime_minutes'] === 0 && $line['complementary_minutes'] === 0 && $line['unclassified_minutes'] === 0) {
                continue;
            }

            $days[] = [
                'date' => $date,
                'overtime_minutes' => $line['overtime_minutes'],
                'complementary_minutes' => $line['complementary_minutes'],
                'destination' => $line['destination'],
                'unclassified_minutes' => $line['unclassified_minutes'],
            ];
            $totals['overtime_minutes'] += $line['overtime_minutes'];
            $totals['complementary_minutes'] += $line['complementary_minutes'];
            $totals['unclassified_minutes'] += $line['unclassified_minutes'];
        }

        return ['week' => $week->iso(), 'days' => $days, ...$totals];
    }

    public static function level(int $minutes): string
    {
        return match (true) {
            $minutes >= self::YEAR_CAP_MINUTES => 'over',
            $minutes >= self::YEAR_WARNING_MINUTES => 'near',
            default => 'ok',
        };
    }

    /** Minutos de descanso que genera una hora extra compensada (80 por hora, redondeado). */
    public static function restMinutes(int $overtimeMinutes): int
    {
        return (int) round($overtimeMinutes * self::REST_MINUTES_PER_HOUR / 60);
    }

    /**
     * Aviso a RR. HH. y al responsable al cruzar 60 h o 80 h de horas extra en el año.
     */
    private function warnCap(User $subject, int $before, int $after): void
    {
        foreach ([self::YEAR_CAP_MINUTES, self::YEAR_WARNING_MINUTES] as $threshold) {
            if ($before < $threshold && $after >= $threshold) {
                PeopleNotifier::send(PeopleAccess::companyDeciders($subject), new OvertimeCapReached($subject, $after, $threshold === self::YEAR_CAP_MINUTES));

                return;
            }
        }
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, array<string, true>>
     */
    private function confirmedMonths(array $userIds, string $from, string $to): array
    {
        $months = [];

        MonthClose::query()
            ->whereIn('user_id', $userIds)
            ->where('status', MonthCloseStatus::Confirmed->value)
            ->whereBetween('month', [substr($from, 0, 7).'-01', $to])
            ->get(['user_id', 'month'])
            ->each(function (MonthClose $close) use (&$months): void {
                $months[$close->user_id][$close->monthKey()] = true;
            });

        return $months;
    }
}
