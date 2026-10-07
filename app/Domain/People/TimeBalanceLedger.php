<?php

namespace App\Domain\People;

use App\Enums\BalanceMovementKind;
use App\Models\OvertimeDecision;
use App\Models\TimeBalanceMovement;
use App\Models\User;
use App\Support\Duration;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El saldo de horas de cada persona (PLAN-FASE-11 §7.2; D-350; W-052), como HourBankLedger con las
 * bolsas de los clientes pero de la jornada: un libro de movimientos de **solo alta** (nunca se
 * edita un saldo). En la interfaz se llama «Saldo de horas», nunca «bolsa».
 *
 * - **+ horas extra compensadas**: las escribe la decisión de horas extra (80 minutos por hora).
 * - **− descanso disfrutado** y **− pagado**: los anota su responsable o RR. HH., nunca ella misma,
 *   con motivo; no pueden dejar el saldo en negativo (las horas no se «deben» a la empresa).
 * - **± ajuste** y **± saldo inicial** (lo que venga de Woffu): solo RR. HH., con motivo.
 * - Si una decisión que compensaba se sustituye, se revierte con un ajuste automático.
 * - **Plazo de 4 meses** (convenio de publicidad, art. 22): cada hora a compensar tiene su fecha
 *   límite; los descansos y pagos se descuentan de las más antiguas primero (FIFO) y la pantalla
 *   avisa de las que vencen o han vencido sin disfrutar.
 */
final class TimeBalanceLedger
{
    /** Meses para disfrutar el descanso que compensa una hora extra (convenio, art. 22). */
    public const int COMPENSATION_MONTHS = 4;

    public function __construct(private readonly RegisterHasher $hasher) {}

    /**
     * Anota un movimiento a mano.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function record(User $actor, User $subject, BalanceMovementKind $kind, int $minutes, string $date, string $reason): TimeBalanceMovement
    {
        if (! PeopleAccess::decidesFor($actor, $subject)) {
            throw new AuthorizationException;
        }

        if (in_array($kind, [BalanceMovementKind::Adjustment, BalanceMovementKind::OpeningBalance], true) && ! PeopleAccess::managesAll($actor)) {
            throw new AuthorizationException;
        }

        if (! in_array($kind, BalanceMovementKind::manual(), true)) {
            throw ValidationException::withMessages(['kind' => __('people.errors.balance_kind')]);
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => __('people.errors.balance_reason')]);
        }

        if ($minutes === 0) {
            throw ValidationException::withMessages(['minutes' => __('people.errors.balance_zero')]);
        }

        $minutes = $kind->sign() === 0 ? $minutes : $kind->sign() * abs($minutes);

        if ($date > LocalTime::todayString()) {
            throw ValidationException::withMessages(['date' => __('people.errors.balance_future')]);
        }

        $movement = DB::transaction(function () use ($actor, $subject, $kind, $minutes, $date, $reason): TimeBalanceMovement {
            User::query()->whereKey($subject->id)->lockForUpdate()->value('id');

            if ($minutes < 0 && $this->balance($subject->id) + $minutes < 0) {
                throw ValidationException::withMessages(['minutes' => __('people.errors.balance_negative')]);
            }

            return $this->append($subject->id, $kind, $minutes, $date, $reason, $actor->id);
        });

        activity('people-register')
            ->causedBy($actor)
            ->performedOn($movement)
            ->event('balance_recorded')
            ->withProperties(['user_id' => $subject->id, 'kind' => $kind->value, 'minutes' => $minutes, 'date' => $date, 'reason' => $reason])
            ->log('balance.recorded');

        return $movement;
    }

    /** + horas extra compensadas con descanso (lo llama OvertimeService dentro de su transacción). */
    public function credit(OvertimeDecision $decision, User $actor): TimeBalanceMovement
    {
        return $this->append(
            $decision->user_id,
            BalanceMovementKind::Overtime,
            OvertimeService::restMinutes($decision->overtime_minutes),
            $decision->date->toDateString(),
            (string) __('people.balance.reasons.overtime', ['minutes' => Duration::format($decision->overtime_minutes)]),
            $actor->id,
            $decision->id,
        );
    }

    /** Revierte lo que sumó una decisión sustituida (lo llama OvertimeService). */
    public function reverse(OvertimeDecision $previous, User $actor, OvertimeDecision $replacement): TimeBalanceMovement
    {
        return $this->append(
            $previous->user_id,
            BalanceMovementKind::Adjustment,
            -OvertimeService::restMinutes($previous->overtime_minutes),
            $previous->date->toDateString(),
            (string) __('people.balance.reasons.reversal', ['date' => $previous->date->format('d/m/Y')]),
            $actor->id,
            $previous->id,
        );
    }

    /** Saldo actual en minutos. */
    public function balance(int $userId): int
    {
        return (int) TimeBalanceMovement::query()->where('user_id', $userId)->sum('minutes');
    }

    /**
     * Movimientos de una persona, los últimos primero.
     *
     * @return list<TimeBalanceMovement>
     */
    public function movements(int $userId, int $limit = 100): array
    {
        return array_values(TimeBalanceMovement::query()
            ->with('author:id,name')
            ->where('user_id', $userId)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->all());
    }

    /**
     * Lo que queda por disfrutar de cada abono (horas extra, saldo inicial y ajustes positivos),
     * descontando los cargos de los más antiguos primero, con su fecha límite (4 meses) y si ha
     * vencido.
     *
     * @return list<array{date: string, minutes: int, remaining_minutes: int, deadline: string, expired: bool, kind: string}>
     */
    public function pendingCompensation(int $userId, ?CarbonImmutable $today = null): array
    {
        $today ??= LocalTime::today();
        $movements = TimeBalanceMovement::query()->where('user_id', $userId)->orderBy('date')->orderBy('id')->get();
        $credits = [];
        $debit = 0;

        foreach ($movements as $movement) {
            if ($movement->minutes > 0) {
                $credits[] = ['movement' => $movement, 'remaining' => $movement->minutes];
            } else {
                $debit += -$movement->minutes;
            }
        }

        foreach ($credits as $index => $credit) {
            $used = min($credit['remaining'], $debit);
            $credits[$index]['remaining'] -= $used;
            $debit -= $used;
        }

        $pending = [];

        foreach ($credits as $credit) {
            if ($credit['remaining'] <= 0) {
                continue;
            }

            $movement = $credit['movement'];
            $deadline = $movement->date->addMonthsNoOverflow(self::COMPENSATION_MONTHS);

            $pending[] = [
                'date' => $movement->date->toDateString(),
                'minutes' => $movement->minutes,
                'remaining_minutes' => $credit['remaining'],
                'deadline' => $deadline->toDateString(),
                'expired' => $deadline->lessThan($today),
                'kind' => $movement->kind->value,
            ];
        }

        return $pending;
    }

    private function append(int $userId, BalanceMovementKind $kind, int $minutes, string $date, string $reason, ?int $by, ?int $decisionId = null): TimeBalanceMovement
    {
        $movement = new TimeBalanceMovement;
        $movement->forceFill([
            'user_id' => $userId,
            'date' => $date,
            'minutes' => $minutes,
            'kind' => $kind,
            'reason' => $reason,
            'overtime_decision_id' => $decisionId,
            'created_by' => $by,
            'created_at' => CarbonImmutable::now()->startOfSecond(),
        ]);
        $movement->hash = $this->hasher->balanceMovement($movement);
        $movement->save();

        return $movement;
    }

    /** Para la supresión del mes 49 (RegisterPruner): el saldo que arrastran los movimientos borrados. */
    public function carryForward(int $userId, int $minutes, string $date): ?TimeBalanceMovement
    {
        if ($minutes === 0) {
            return null;
        }

        return $this->append($userId, BalanceMovementKind::OpeningBalance, $minutes, $date, (string) __('people.balance.reasons.carry_forward'), null);
    }
}
