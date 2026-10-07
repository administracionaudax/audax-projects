<?php

namespace App\Domain\Privacy\Export\Sections;

use App\Models\ClockEvent;
use App\Models\User;

/**
 * Registro de jornada (Fase 11, R2; D-357): cada fila de mi cadena, también las anulaciones, con su
 * huella. La IP no se guarda nunca (solo su huella HMAC, que no sirve para saber cuál era).
 */
final class ClockEventsSection extends Section
{
    public function key(): string
    {
        return 'registro-jornada';
    }

    protected function textKey(): string
    {
        return 'clock_events';
    }

    protected function columnKeys(): array
    {
        return ['seq', 'kind', 'occurred_at', 'recorded_at', 'work_mode', 'pause_type', 'source', 'voids_seq', 'correction_id', 'author', 'user_agent', 'prev_hash', 'hash'];
    }

    public function rows(User $user): iterable
    {
        $query = ClockEvent::query()->with(['author:id,name', 'voidedEvent:id,seq'])->where('user_id', $user->id)->orderBy('seq');

        foreach ($query->lazy(500) as $event) {
            yield [
                'seq' => $event->seq,
                'kind' => $event->kind->value,
                'occurred_at' => self::instant($event->occurred_at),
                'recorded_at' => self::instant($event->recorded_at),
                'work_mode' => $event->work_mode?->value,
                'pause_type' => $event->pause_type?->value,
                'source' => $event->source->value,
                'voids_seq' => $event->voidedEvent?->seq,
                'correction_id' => $event->correction_id,
                'author' => $event->author?->name,
                'user_agent' => $event->user_agent,
                'prev_hash' => $event->prev_hash,
                'hash' => $event->hash,
            ];
        }
    }
}
