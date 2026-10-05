<?php

namespace App\Domain\Weeklies;

use App\Enums\WeeklyCycleStatus;
use App\Jobs\UpdateWeeklySatisfaction;
use App\Models\User;
use App\Models\WeeklyCycle;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cerrar una semana (F-035, F-070, F-089 y F-092, D-191), como `ws:close-week-and-update-satisfaction`:
 * 1. solo la activa y con el texto y el audio generados (WeeklyReportState::closeBlockers); quien
 *    falte por enviar no lo impide (la página solo avisa),
 * 2. en una transacción con la semana bloqueada: congela la participación y los exentos
 *    (WeeklyEligibility::freeze) y la marca cerrada,
 * 3. abre la siguiente (WeeklyCycleOpener::afterClose),
 * 4. en segundo plano (cola `ai`), la satisfacción y el evento del aviso (UpdateWeeklySatisfaction).
 */
final class WeeklyCycleCloser
{
    public function __construct(
        private readonly WeeklyEligibility $eligibility,
        private readonly WeeklyCycleOpener $opener,
    ) {}

    /**
     * @return WeeklyCycle la semana activa tras cerrar (la siguiente)
     *
     * @throws ValidationException si falta el texto o el audio, o ya no está activa
     */
    public function close(WeeklyCycle $cycle, User $by): WeeklyCycle
    {
        DB::transaction(function () use ($cycle, $by): void {
            $locked = WeeklyCycle::query()->lockForUpdate()->findOrFail($cycle->id);
            $blockers = WeeklyReportState::closeBlockers($locked, self::hasAudio($locked));

            if ($blockers !== []) {
                throw ValidationException::withMessages([
                    'cycle' => array_map(fn (string $blocker): string => (string) __("weeklies.close.blockers.{$blocker}"), $blockers),
                ]);
            }

            $this->eligibility->freeze($locked);
            $locked->forceFill([
                'status' => WeeklyCycleStatus::Closed,
                'closed_at' => now(),
                'closed_by' => $by->id,
            ])->save();

            $cycle->setRawAttributes($locked->getAttributes(), true);
        });

        $next = $this->opener->afterClose($cycle);
        UpdateWeeklySatisfaction::dispatch($cycle->id, $by->id, $next->id);

        return $next;
    }

    /** El audio cuenta como generado si hay audio completo o alguna sección con su MP3. */
    public static function hasAudio(WeeklyCycle $cycle): bool
    {
        return $cycle->audio_path !== null || $cycle->audioSections()->whereNotNull('path')->exists();
    }
}
