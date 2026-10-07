<?php

namespace App\Domain\People;

use App\Models\ClockEvent;
use App\Models\Setting;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;

/**
 * Desde qué día se lleva el registro en la app (D-337): el ajuste `people_register_starts_on` o, si
 * no está, el día del primer fichaje de toda la empresa. Antes de ese día no hay jornada teórica ni
 * incidencias («Sin fichajes» sería falso: el registro se llevaba en Woffu). Sin ningún fichaje
 * todavía, null: aún no hay registro en la app.
 */
final class RegisterStart
{
    /** @var array{0: string|null}|null */
    private static ?array $memo = null;

    public static function date(): ?string
    {
        $configured = Setting::get('people_register_starts_on');

        if (is_string($configured) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $configured) === 1) {
            return $configured;
        }

        if (self::$memo !== null) {
            return self::$memo[0];
        }

        $first = ClockEvent::query()->min('occurred_at');
        $date = $first === null ? null : LocalTime::dateOf(CarbonImmutable::parse((string) $first, 'UTC'));

        // Solo se recuerda cuando ya hay registro: hasta el primer fichaje se vuelve a mirar.
        if ($date !== null && ! app()->runningUnitTests()) {
            self::$memo = [$date];
        }

        return $date;
    }

    public static function forget(): void
    {
        self::$memo = null;
    }
}
