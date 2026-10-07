<?php

namespace App\Domain\Absences;

use Carbon\CarbonImmutable;

/**
 * Festivos nacionales de España de un año (D-050), calculados en local, sin servicios externos
 * (SPEC §15: nada a terceros): 1 y 6 de enero, Viernes Santo, 1 de mayo, 15 de agosto, 12 de
 * octubre, 1 de noviembre y 6, 8 y 25 de diciembre. Los autonómicos, los locales y los traslados
 * a lunes de cada comunidad se añaden a mano o importando un .ics o un CSV (HolidayImporter).
 */
final class SpanishNationalHolidays
{
    public const int MIN_YEAR = 2000;

    public const int MAX_YEAR = 2100;

    /**
     * Festivos de fecha fija: MM-DD → clave del nombre (lang/es/absences.php, holidays.names).
     */
    private const array FIXED = [
        '01-01' => 'new_year',
        '01-06' => 'epiphany',
        '05-01' => 'labour_day',
        '08-15' => 'assumption',
        '10-12' => 'national_day',
        '11-01' => 'all_saints',
        '12-06' => 'constitution_day',
        '12-08' => 'immaculate_conception',
        '12-25' => 'christmas',
    ];

    /**
     * Los festivos del año, por fecha.
     *
     * @return list<array{date: string, name: string, level: string, source: null}>
     */
    public function forYear(int $year): array
    {
        $holidays = [];

        foreach (self::FIXED as $day => $key) {
            $holidays[] = ['date' => sprintf('%04d-%s', $year, $day), 'name' => self::name($key), 'level' => 'national', 'source' => null];
        }

        $holidays[] = ['date' => self::goodFriday($year)->toDateString(), 'name' => self::name('good_friday'), 'level' => 'national', 'source' => null];

        usort($holidays, fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

        return $holidays;
    }

    /**
     * Domingo de Pascua (calendario gregoriano), con el algoritmo anónimo de Meeus, Jones y
     * Butcher: solo aritmética entera, sin depender de la extensión calendar de PHP.
     */
    public static function easterSunday(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day);
    }

    /**
     * Viernes Santo: dos días antes del domingo de Pascua.
     */
    public static function goodFriday(int $year): CarbonImmutable
    {
        return self::easterSunday($year)->subDays(2);
    }

    private static function name(string $key): string
    {
        $name = __("absences.holidays.names.{$key}");

        return is_string($name) ? $name : $key;
    }
}
