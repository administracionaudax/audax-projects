<?php

namespace App\Domain\Absences;

use App\Enums\LeaveCalendarDayKind;
use App\Models\LeaveCalendarDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Los días especiales del calendario laboral (Fase 11, R3; W-034 y W-039; D-366):
 * - **media jornada** (`half_day`): la jornada teórica de ese día es la mitad (Capacity, en toda la
 *   app) y unas vacaciones ese día descuentan medio día (AbsenceCost). Un festivo manda sobre ella.
 * - **bloqueados** (`blocked`): no se pueden pedir vacaciones de los tipos que los respetan.
 *
 * Son pocas filas: se leen todas una vez por petición o por trabajo de la cola (instancia `scoped`
 * del contenedor, AppServiceProvider), porque Capacity las mira en cada cálculo. Quien las cambia
 * llama a forget().
 */
final class LeaveCalendar
{
    /** @var Collection<int, LeaveCalendarDay>|null */
    private ?Collection $rows = null;

    /** Olvida lo leído (tras crear, cambiar o borrar un día especial). */
    public static function forget(): void
    {
        app()->forgetInstance(self::class);
    }

    /**
     * @return Collection<int, LeaveCalendarDay>
     */
    private function rows(): Collection
    {
        return $this->rows ??= LeaveCalendarDay::query()->orderBy('start_date')->orderBy('id')->get();
    }

    /**
     * @return Collection<int, LeaveCalendarDay>
     */
    private static function of(LeaveCalendarDayKind $kind, string $from, string $to): Collection
    {
        return app(self::class)->rows()
            ->filter(fn (LeaveCalendarDay $row): bool => $row->kind === $kind && $row->start_date->toDateString() <= $to && $row->end_date->toDateString() >= $from)
            ->values();
    }

    /**
     * Los días de media jornada entre dos fechas, con su nombre.
     *
     * @return array<string, string> AAAA-MM-DD → nombre
     */
    public static function halfDays(string $from, string $to): array
    {
        return self::expand(LeaveCalendarDayKind::HalfDay, $from, $to);
    }

    /**
     * Los periodos bloqueados que se solapan con [from, to], por fecha de inicio.
     *
     * @return list<LeaveCalendarDay>
     */
    public static function blocked(string $from, string $to): array
    {
        return array_values(self::of(LeaveCalendarDayKind::Blocked, $from, $to)->all());
    }

    /**
     * @return array<string, string>
     */
    private static function expand(LeaveCalendarDayKind $kind, string $from, string $to): array
    {
        $days = [];
        foreach (self::of($kind, $from, $to) as $row) {
            $day = CarbonImmutable::parse(max($row->start_date->toDateString(), $from));
            $last = min($row->end_date->toDateString(), $to);

            for (; $day->toDateString() <= $last; $day = $day->addDay()) {
                $days[$day->toDateString()] ??= $row->name;
            }
        }

        return $days;
    }
}
