<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Hora local de la empresa (config app.display_timezone, Europe/Madrid). Los instantes se guardan
 * en UTC; las fechas de imputación (`date`) son fechas locales: "hoy" siempre es hoy en Madrid.
 */
final class LocalTime
{
    public static function timezone(): string
    {
        return (string) config('app.display_timezone', 'Europe/Madrid');
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone());
    }

    public static function today(): CarbonImmutable
    {
        return self::now()->startOfDay();
    }

    public static function todayString(): string
    {
        return self::now()->toDateString();
    }

    /**
     * Fecha local (Y-m-d) de un instante.
     */
    public static function dateOf(\DateTimeInterface $instant): string
    {
        return CarbonImmutable::instance($instant)->setTimezone(self::timezone())->toDateString();
    }
}
