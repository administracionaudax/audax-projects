<?php

namespace App\Domain\Absences;

use App\Enums\AbsenceStatus;
use App\Models\Absence;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de una ausencia (D-049), comunes a solicitarla y a registrarla ya aprobada:
 * - la persona es interna y está activa,
 * - fin ≥ inicio y, como mucho, un año (de un día al mismo día del año siguiente, sin incluirlo),
 * - fechas entre dos años atrás y dos años adelante (lo que calculan la carga y los informes),
 * - `partial_minutes` solo en ausencias de un día, entre 0:01 y 23:59,
 * - sin solaparse con otra ausencia solicitada o aprobada de la misma persona.
 * Lanza una ValidationException con todos los errores a la vez. La usa AbsenceService dentro de
 * su transacción, con la persona bloqueada: dos solicitudes a la vez no se solapan.
 */
final class AbsenceRules
{
    public const int YEARS_AROUND = 2;

    public const int MAX_PARTIAL_MINUTES = 24 * 60 - 1;

    /**
     * @throws ValidationException
     */
    public function check(User $actor, User $target, AbsenceData $data): void
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

        if ($errors === [] && ($overlap = $this->overlapping($target, $data)) !== null) {
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
