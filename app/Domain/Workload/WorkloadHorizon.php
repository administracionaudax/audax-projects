<?php

namespace App\Domain\Workload;

use Carbon\CarbonImmutable;

/**
 * Horizontes de la vista Carga (SPEC §9, D-052), con los valores de la URL en español
 * (?horizonte=…). Por defecto, la semana que viene: la pregunta principal.
 * - semana actual: de hoy al domingo (lo que ya ha pasado no lleva carga planificada, D-051),
 * - semana que viene: de lunes a domingo,
 * - próximas 4 semanas: de hoy al domingo de la cuarta semana (la actual incluida), por días,
 * - próximos 3 meses: de hoy al domingo de la semana 13 (la actual incluida), por semanas.
 */
enum WorkloadHorizon: string
{
    case CurrentWeek = 'semana-actual';
    case NextWeek = 'semana-que-viene';
    case FourWeeks = '4-semanas';
    case ThreeMonths = '3-meses';

    /** Semanas del horizonte de 3 meses. */
    public const int THREE_MONTHS_WEEKS = 13;

    public static function default(): self
    {
        return self::NextWeek;
    }

    /**
     * Un valor desconocido (enlace viejo o manipulado) abre el de por defecto, nunca un error.
     */
    public static function fromQuery(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::default()) : self::default();
    }

    /**
     * Primer y último día (fechas locales, sin hora).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function bounds(CarbonImmutable $today): array
    {
        $day = CarbonImmutable::parse($today->toDateString());
        $monday = $day->subDays($day->dayOfWeekIso - 1);

        return match ($this) {
            self::CurrentWeek => [$day, $monday->addDays(6)],
            self::NextWeek => [$monday->addDays(7), $monday->addDays(13)],
            self::FourWeeks => [$day, $monday->addDays(4 * 7 - 1)],
            self::ThreeMonths => [$day, $monday->addDays(self::THREE_MONTHS_WEEKS * 7 - 1)],
        };
    }

    /**
     * El de 3 meses se ve por semanas; el resto, por días.
     */
    public function byWeek(): bool
    {
        return $this === self::ThreeMonths;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
