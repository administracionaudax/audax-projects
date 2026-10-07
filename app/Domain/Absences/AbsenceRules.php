<?php

namespace App\Domain\Absences;

use App\Enums\AbsenceStatus;
use App\Enums\LeaveUnit;
use App\Models\Absence;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de una ausencia (D-049), comunes a solicitarla, a registrarla ya aprobada y a modificarla:
 * - la persona es interna y está activa,
 * - fin ≥ inicio y, como mucho, un año (de un día al mismo día del año siguiente, sin incluirlo),
 * - fechas entre dos años atrás y dos años adelante (lo que calculan la carga y los informes),
 * - `partial_minutes` solo en ausencias de un día, entre 0:01 y 23:59,
 * - sin solaparse con otra ausencia solicitada o aprobada de la misma persona.
 * Lanza una ValidationException con todos los errores a la vez. La usa AbsenceService dentro de
 * su transacción, con la persona bloqueada: dos solicitudes a la vez no se solapan.
 *
 * Fase 11, R3 (D-361, D-364 y D-366), con el módulo `people` visible para quien actúa (LeaveMode):
 * - el tipo del catálogo tiene que estar activo (salvo al modificar una que ya lo tenía),
 * - la franja de las de horas: las dos horas, la de fin después de la de inicio y de un solo día;
 *   un tipo en días no lleva franja,
 * - al **pedirla la propia persona** ($own): no puede caer en días bloqueados si el tipo los respeta
 *   (vacaciones) y, si el tipo no deja pedir sin saldo, tiene que caber en lo disponible en esas
 *   fechas (contando lo pendiente). Quien registra o modifica una para otra persona (responsable o
 *   RR. HH.) no tiene esos límites: decide la empresa.
 */
final class AbsenceRules
{
    public function __construct(
        private readonly AbsenceCost $cost,
        private readonly LeaveBalances $balances,
    ) {}

    public const int YEARS_AROUND = 2;

    public const int MAX_PARTIAL_MINUTES = 24 * 60 - 1;

    /**
     * @param  int|null  $ignoreId  La ausencia que se modifica: no cuenta como solape consigo misma.
     *
     * @throws ValidationException
     */
    public function check(User $actor, User $target, AbsenceData $data, ?int $ignoreId = null, bool $own = false, ?int $previousTypeId = null): void
    {
        $errors = [];

        if (! $target->is_active) {
            $errors['user_id'][] = AbsenceText::get('absences.errors.user_inactive');
        }
        if ($target->isClient()) {
            $errors['user_id'][] = AbsenceText::get('absences.errors.user_not_internal');
        }

        $start = CarbonImmutable::parse($data->startDate);
        $end = CarbonImmutable::parse($data->endDate);
        $today = CarbonImmutable::parse(LocalTime::todayString());
        $min = $today->subYears(self::YEARS_AROUND);
        $max = $today->addYears(self::YEARS_AROUND);

        if ($data->endDate < $data->startDate) {
            $errors['end_date'][] = AbsenceText::get('absences.errors.end_before_start');
        } elseif ($end->greaterThanOrEqualTo($start->addYear())) {
            $errors['end_date'][] = AbsenceText::get('absences.errors.too_long');
        }

        if ($start->lessThan($min) || $end->greaterThan($max)) {
            $errors['start_date'][] = AbsenceText::get('absences.errors.too_far', [
                'from' => $min->format('d/m/Y'),
                'to' => $max->format('d/m/Y'),
            ]);
        }

        if ($data->partialMinutes !== null) {
            if ($data->startDate !== $data->endDate) {
                $errors['partial_minutes'][] = AbsenceText::get('absences.errors.partial_single_day');
            } elseif ($data->partialMinutes < 1 || $data->partialMinutes > self::MAX_PARTIAL_MINUTES) {
                $errors['partial_minutes'][] = AbsenceText::get('absences.errors.partial_range');
            }
        }

        if (LeaveMode::on($actor)) {
            $errors = array_merge_recursive($errors, $this->leaveErrors($target, $data, $ignoreId, $own, $previousTypeId));
        } elseif ($data->hasSlot()) {
            $errors['start_time'][] = AbsenceText::get('leave.errors.slot_unavailable');
        }

        if ($errors === [] && ($overlap = $this->overlapping($target, $data, $ignoreId)) !== null) {
            $replace = [
                'type' => $overlap->type->label(),
                'period' => AbsenceText::period($overlap->start_date->toDateString(), $overlap->end_date->toDateString(), $overlap->partial_minutes),
                'status' => AbsenceText::status($overlap->status),
                'name' => $target->name,
            ];

            $errors['start_date'][] = $actor->id === $target->id
                ? AbsenceText::get('absences.errors.overlap', $replace)
                : AbsenceText::get('absences.errors.overlap_other', $replace);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Las reglas del catálogo, la franja, los días bloqueados y el saldo (R3).
     *
     * @return array<string, list<string>>
     */
    private function leaveErrors(User $target, AbsenceData $data, ?int $ignoreId, bool $own, ?int $previousTypeId): array
    {
        $errors = [];
        $type = $data->leaveType;

        if ($type === null) {
            return ['leave_type_id' => [AbsenceText::get('leave.errors.type_missing')]];
        }

        if (! $type->active && $type->id !== $previousTypeId) {
            $errors['leave_type_id'][] = AbsenceText::get('leave.errors.type_inactive', ['type' => $type->name]);
        }

        if ($data->hasSlot()) {
            if ($type->unit !== LeaveUnit::Hours) {
                $errors['start_time'][] = AbsenceText::get('leave.errors.slot_days', ['type' => $type->name]);
            } elseif ($data->startTime === null || $data->endTime === null) {
                $errors['start_time'][] = AbsenceText::get('leave.errors.slot_incomplete');
            } elseif ($data->endTime <= $data->startTime) {
                $errors['end_time'][] = AbsenceText::get('leave.errors.slot_order');
            }
        }

        if ($errors !== [] || ! $own || $data->endDate < $data->startDate) {
            return $errors;
        }

        if ($type->respects_blocked_days && ($blocked = LeaveCalendar::blocked($data->startDate, $data->endDate)) !== []) {
            $errors['start_date'][] = AbsenceText::get('leave.errors.blocked', [
                'name' => $blocked[0]->name,
                'period' => AbsenceText::period($blocked[0]->start_date->toDateString(), $blocked[0]->end_date->toDateString()),
            ]);
        }

        if ($type->hasAllowance() && ! $type->allow_without_balance) {
            $days = $this->cost->days($target->id, $type, $data->startDate, $data->endDate, $data->partialMinutes);
            $check = $this->balances->shortfall($target, $type, $days, $ignoreId);

            if ($check['shortfall'] > 0) {
                $errors['start_date'][] = AbsenceText::get('leave.errors.no_balance', [
                    'type' => $type->name,
                    'requested' => LeaveFormat::amount(AbsenceCost::total($days), $type->unit),
                    'available' => LeaveFormat::amount(max($check['available'], 0), $type->unit),
                ]);
            }
        }

        return $errors;
    }

    /**
     * La primera ausencia solicitada o aprobada de la persona que se solapa con esas fechas.
     */
    public function overlapping(User $target, AbsenceData $data, ?int $ignoreId = null): ?Absence
    {
        return Absence::query()
            ->where('user_id', $target->id)
            ->whereIn('status', [AbsenceStatus::Requested->value, AbsenceStatus::Approved->value])
            ->overlapping($data->startDate, $data->endDate)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->orderBy('start_date')
            ->first(['id', 'user_id', 'type', 'start_date', 'end_date', 'partial_minutes', 'status']);
    }
}
