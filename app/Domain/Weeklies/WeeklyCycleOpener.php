<?php

namespace App\Domain\Weeklies;

use App\Enums\WeeklyCycleStatus;
use App\Models\WeeklyCycle;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Abre las semanas de la weekly (D-150, D-155, F-040, F-069 y F-070). Una sola activa: la garantiza
 * el índice único parcial; si dos procesos abren a la vez, uno gana y el otro recibe la activa.
 *
 * Qué semana se abre («la que toca»): la más tardía de
 * - la semana en curso (Madrid),
 * - la siguiente a la última semana que existe (una semana ya cerrada no se vuelve a abrir),
 * - la siguiente a la que se acaba de cerrar o borrar ($after; F-069 y F-070).
 *
 * Lo usan:
 * - el planificador (`weeklies:open-week`, cada día a las 00:05: el lunes abre la semana y, si el
 *   servidor estuvo parado, la abre en cuanto vuelve),
 * - «Iniciar la semana» de quien gestiona (F-040),
 * - borrar la última semana (F-069: abre la siguiente),
 * - el cierre de 10.3: afterClose() es su punto de enganche.
 */
final class WeeklyCycleOpener
{
    public function __construct(private readonly WeeklyCalendar $calendar = new WeeklyCalendar) {}

    /**
     * Abre la semana que toca si no hay ninguna activa. Devuelve la semana activa (la que ya había o
     * la nueva).
     */
    public function ensureOpen(?CarbonInterface $now = null): WeeklyCycle
    {
        return WeeklyCycle::query()->active()->first() ?? $this->open($this->target($now));
    }

    /**
     * Punto de enganche del cierre (10.3, F-070): tras marcar la semana como cerrada, abre la
     * siguiente (o la en curso, si la cerrada era antigua). Si ya hay una activa, la devuelve.
     */
    public function afterClose(WeeklyCycle $closed, ?CarbonInterface $now = null): WeeklyCycle
    {
        return WeeklyCycle::query()->active()->first() ?? $this->open($this->target($now, $closed));
    }

    /**
     * Tras borrar una semana (F-069): si era la más reciente, abre la siguiente; si no, nada. Devuelve
     * la semana abierta, o null.
     */
    public function afterDelete(WeeklyCycle $deleted, ?CarbonInterface $now = null): ?WeeklyCycle
    {
        $newer = WeeklyCycle::query()->where('start_date', '>', $deleted->start_date->toDateString())->exists();

        if ($newer || WeeklyCycle::query()->active()->exists()) {
            return null;
        }

        return $this->open($this->target($now, $deleted));
    }

    /**
     * La semana que toca abrir ahora (ver la cabecera).
     */
    public function target(?CarbonInterface $now = null, ?WeeklyCycle $after = null): WeeklyPeriod
    {
        $target = $this->calendar->current($now);

        if ($after !== null) {
            $target = $this->later($target, $this->calendar->next($after->start_date->toDateString()));
        }

        $latest = WeeklyCycle::query()->orderByDesc('start_date')->value('start_date');

        if ($latest !== null) {
            $latestDay = $latest instanceof CarbonInterface ? $latest->toDateString() : substr((string) $latest, 0, 10);

            if ($latestDay >= $target->start->toDateString()) {
                $target = $this->calendar->next($latestDay);
            }
        }

        return $target;
    }

    /**
     * Crea la semana activa de ese periodo. Si otra se ha abierto a la vez (índice único), devuelve
     * esa.
     *
     * @throws WeeklyRuleViolation ALREADY_ACTIVE si ya había una activa antes de empezar
     */
    public function open(WeeklyPeriod $period): WeeklyCycle
    {
        if (WeeklyCycle::query()->active()->exists()) {
            throw new WeeklyRuleViolation(WeeklyRuleViolation::ALREADY_ACTIVE);
        }

        try {
            return DB::transaction(fn (): WeeklyCycle => WeeklyCycle::query()->create([
                ...$period->toAttributes(),
                'status' => WeeklyCycleStatus::Active,
            ]));
        } catch (QueryException $e) {
            $active = WeeklyCycle::query()->active()->first();

            if ($active !== null) {
                return $active;
            }

            throw $e;
        }
    }

    private function later(WeeklyPeriod $a, WeeklyPeriod $b): WeeklyPeriod
    {
        return CarbonImmutable::instance($b->start)->greaterThan($a->start) ? $b : $a;
    }
}
