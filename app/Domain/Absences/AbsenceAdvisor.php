<?php

namespace App\Domain\Absences;

use App\Enums\AbsenceType;
use App\Enums\LeaveUnit;
use App\Models\Absence;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;

/**
 * Avisos de una ausencia que **no bloquean** (Fase 11, R3; D-364): los ve la persona al pedirla y
 * quien la aprueba en la bandeja. Nunca impiden un derecho: la empresa decide.
 *
 * - `short_notice`: vacaciones que empiezan antes de 2 meses (art. 38.3 ET: la persona conoce sus
 *   fechas al menos 2 meses antes; solo aviso, porque las pide ella).
 * - `notice`: el tipo pide un preaviso (permiso parental, 10 días; nacimiento, 15) y no se cumple.
 * - `over_amount`: pide más de lo que da el permiso (15 días por matrimonio…; con desplazamiento,
 *   lo que da de más).
 * - `document`: el tipo pide justificante y aún no hay ninguno.
 * - `unpaid_excess`: en la fuerza mayor, lo que pasa de las horas retribuidas (4 días al año) se
 *   puede pedir, pero sin retribuir.
 * - `non_working_start`: un permiso en días naturales que empieza en un día sin jornada (el
 *   Supremo lo cuenta desde el primer día laborable).
 *
 * @phpstan-type Warning array{code: string, message: string}
 */
final class AbsenceAdvisor
{
    /** Meses de antelación de las vacaciones (art. 38.3 ET). */
    public const int VACATION_NOTICE_MONTHS = 2;

    public function __construct(
        private readonly AbsenceCost $cost,
        private readonly LeaveBalances $balances,
    ) {}

    /**
     * @param  array<string, int>|null  $days  El coste por día, si ya se ha calculado.
     * @return list<Warning>
     */
    public function warnings(User $target, LeaveType $type, string $start, string $end, ?int $partialMinutes, ?string $requestedOn = null, int $documents = 0, ?int $ignoreId = null, ?array $days = null): array
    {
        $requestedOn ??= LocalTime::todayString();
        $warnings = [];
        $days ??= $this->cost->days($target->id, $type, $start, $end, $partialMinutes);
        $total = AbsenceCost::total($days);

        if ($type->category === AbsenceType::Vacation && $start >= $requestedOn
            && $start < CarbonImmutable::parse($requestedOn)->addMonthsNoOverflow(self::VACATION_NOTICE_MONTHS)->toDateString()) {
            $warnings[] = ['code' => 'short_notice', 'message' => AbsenceText::get('leave.warnings.short_notice')];
        }

        if ($type->notice_days !== null && $type->notice_days > 0 && $start >= $requestedOn
            && $start < CarbonImmutable::parse($requestedOn)->addDays($type->notice_days)->toDateString()) {
            $warnings[] = ['code' => 'notice', 'message' => AbsenceText::get('leave.warnings.notice', ['days' => $type->notice_days])];
        }

        if ($type->default_amount !== null && $type->default_amount > 0 && $total > $type->default_amount + ($type->travel_extra ?? 0)) {
            $warnings[] = ['code' => 'over_amount', 'message' => AbsenceText::get('leave.warnings.over_amount', [
                'type' => $type->name,
                'amount' => LeaveFormat::amount($type->default_amount, $type->unit),
                'extra' => $type->travel_extra ? AbsenceText::get('leave.warnings.with_travel', ['amount' => LeaveFormat::amount($type->travel_extra, $type->unit)]) : '',
            ])];
        }

        if ($type->requires_document && $documents === 0) {
            $warnings[] = ['code' => 'document', 'message' => AbsenceText::get('leave.warnings.document')];
        }

        if ($type->hasAllowance() && $type->allow_without_balance) {
            $check = $this->balances->shortfall($target, $type, $days, $ignoreId);

            if ($check['shortfall'] > 0) {
                $warnings[] = ['code' => 'unpaid_excess', 'message' => AbsenceText::get('leave.warnings.unpaid_excess', [
                    'amount' => LeaveFormat::amount($check['shortfall'], $type->unit),
                ])];
            }
        }

        if ($type->unit === LeaveUnit::CalendarDays && $partialMinutes === null) {
            $first = $this->cost->days($target->id, LeaveCatalog::workingProbe(), $start, $start, null);

            if (($first[$start] ?? 0) === 0) {
                $warnings[] = ['code' => 'non_working_start', 'message' => AbsenceText::get('leave.warnings.non_working_start')];
            }
        }

        return $warnings;
    }

    /**
     * Los avisos de una ausencia ya guardada (la bandeja de quien aprueba y «Mis ausencias»): la
     * antelación se mide con el día en que se pidió.
     *
     * @param  array<string, int>|null  $days
     * @return list<Warning>
     */
    public function forAbsence(Absence $absence, ?array $days = null): array
    {
        $type = $absence->leaveType;

        if ($type === null) {
            return [];
        }

        return $this->warnings(
            $absence->user,
            $type,
            $absence->start_date->toDateString(),
            $absence->end_date->toDateString(),
            $absence->partial_minutes,
            $absence->created_at !== null ? LocalTime::dateOf(CarbonImmutable::parse($absence->created_at)) : null,
            $absence->relationLoaded('documents') ? $absence->documents->count() : $absence->documents()->count(),
            $absence->id,
            $days,
        );
    }
}
