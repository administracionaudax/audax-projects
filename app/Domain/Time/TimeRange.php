<?php

namespace App\Domain\Time;

use App\Models\TimeEntry;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Franja horaria de una entrada manual (D-172): fecha más hora de inicio y de fin, en hora de
 * Madrid. Da los instantes en UTC (`started_at`/`ended_at`) y la duración en minutos, que es el
 * tiempo real transcurrido (en los cambios de hora, 01:00–04:00 no siempre son 3 h).
 *
 * - El fin tiene que ser posterior al inicio. «00:00» como fin es la medianoche que cierra el día
 *   (24:00), así que 22:00–00:00 son 2 h del mismo día.
 * - **No cruza la medianoche:** una entrada es de un solo día (la hoja semanal y la aprobación van
 *   por días). 22:00–02:00 se rechaza con un mensaje que explica cómo registrarlo: dos entradas,
 *   hasta las 24:00 y desde las 00:00. El temporizador sí parte por días (TimerService::split),
 *   porque mide solo; aquí lo escribe la persona y partirlo en silencio sorprendería.
 * - Como mucho 24 h, que es lo que cabe en un día.
 */
final readonly class TimeRange
{
    public const string FORMAT = '/^([01]\d|2[0-3]):[0-5]\d$/';

    private function __construct(
        public CarbonImmutable $startedAt,
        public CarbonImmutable $endedAt,
        public int $minutes,
    ) {}

    /**
     * @param  string  $date  "Y-m-d", día local de la entrada
     * @param  string  $start  "H:i"
     * @param  string  $end  "H:i" («00:00» = fin del día)
     *
     * @throws ValidationException con la clave `end_time` (o `start_time` si el formato no vale)
     */
    public static function fromLocal(string $date, string $start, string $end): self
    {
        if (preg_match(self::FORMAT, $start) !== 1) {
            throw ValidationException::withMessages(['start_time' => self::message('time.errors.range_format')]);
        }

        if (preg_match(self::FORMAT, $end) !== 1) {
            throw ValidationException::withMessages(['end_time' => self::message('time.errors.range_format')]);
        }

        $zone = LocalTime::timezone();
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, $zone);

        if ($day === null) {
            throw ValidationException::withMessages(['date' => self::message('time.errors.range_format')]);
        }

        $startedAt = self::at($day, $start);
        $endedAt = $end === '00:00' ? $day->addDay() : self::at($day, $end);

        if ($end !== '00:00' && $end <= $start) {
            throw ValidationException::withMessages(['end_time' => self::message(
                $end === $start ? 'time.errors.range_empty' : 'time.errors.range_midnight',
            )]);
        }

        $minutes = intdiv($endedAt->getTimestamp() - $startedAt->getTimestamp(), 60);

        if ($minutes < 1 || $minutes > TimeEntry::MAX_MINUTES_PER_DAY) {
            throw ValidationException::withMessages(['end_time' => self::message('time.errors.minutes_range')]);
        }

        return new self($startedAt->utc(), $endedAt->utc(), $minutes);
    }

    /**
     * ¿Se pisan dos franjas? Los extremos que se tocan (10:00–11:00 y 11:00–12:00) no se pisan.
     */
    public static function overlaps(CarbonImmutable $startA, CarbonImmutable $endA, CarbonImmutable $startB, CarbonImmutable $endB): bool
    {
        return $startA < $endB && $startB < $endA;
    }

    private static function at(CarbonImmutable $day, string $time): CarbonImmutable
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $day->setTime($hours, $minutes);
    }

    private static function message(string $key): string
    {
        $message = __($key);

        return is_string($message) ? $message : $key;
    }
}
