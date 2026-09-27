<?php

namespace App\Domain\Absences;

use App\Domain\Time\Capacity;
use App\Models\Absence;
use App\Models\Holiday;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * Días laborables de cada ausencia: los de su rango con jornada (WorkSchedule vigente o la jornada
 * por defecto, como Capacity) que no son festivo. Es lo que «cuesta» una ausencia de día completo
 * (5 días de vacaciones de lunes a viernes, 4 si uno es festivo). Dos consultas para todas las
 * ausencias, sean de quien sean. Las de parte del día no cuentan días (se muestran sus horas).
 */
final class AbsenceDays
{
    /**
     * @param  iterable<Absence>  $absences
     * @return array<int, int> id de la ausencia → días laborables
     */
    public function count(iterable $absences): array
    {
        $absences = Collection::make($absences)->filter(fn (Absence $absence): bool => $absence->partial_minutes === null)->values();

        if ($absences->isEmpty()) {
            return [];
        }

        $from = (string) $absences->min(fn (Absence $absence): string => $absence->start_date->toDateString());
        $to = (string) $absences->max(fn (Absence $absence): string => $absence->end_date->toDateString());

        $schedules = WorkSchedule::query()
            ->whereIn('user_id', $absences->pluck('user_id')->unique()->values()->all())
            ->where('valid_from', '<=', $to)
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $from))
            ->orderByDesc('valid_from')
            ->get()
            ->groupBy('user_id');

        $holidays = Holiday::query()->whereBetween('date', [$from, $to])->get(['date'])
            ->mapWithKeys(fn (Holiday $holiday): array => [$holiday->date->toDateString() => true])
            ->all();

        $default = Capacity::defaultWeek();
        $days = [];

        foreach ($absences as $absence) {
            /** @var Collection<int, WorkSchedule> $own */
            $own = $schedules->get($absence->user_id) ?? new Collection;
            $count = 0;

            foreach (CarbonPeriod::create(CarbonImmutable::parse($absence->start_date->toDateString()), CarbonImmutable::parse($absence->end_date->toDateString())) as $day) {
                if (isset($holidays[$day->toDateString()])) {
                    continue;
                }

                $schedule = $own->first(fn (WorkSchedule $candidate): bool => $candidate->coversDate($day));
                $minutes = $schedule?->minutesFor($day) ?? $default[$day->dayOfWeekIso - 1];

                if ($minutes > 0) {
                    $count++;
                }
            }

            $days[$absence->id] = $count;
        }

        return $days;
    }
}
