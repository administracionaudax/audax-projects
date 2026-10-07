<?php

namespace App\Domain\People;

use App\Enums\ClockEventKind;
use App\Enums\CorrectionStatus;
use App\Models\ClockCorrection;
use App\Models\ClockEvent;

/**
 * Comprueba que el registro no se ha tocado (PLAN-FASE-11 §11.1; D-332): por cada persona, que la
 * cadena va de 1 en 1 sin huecos, que cada fila guarda la huella de la anterior y que su propia
 * huella coincide con su contenido; que cada anulación apunta a un fichaje anterior de la misma
 * persona; y que cada corrección decidida conserva su sello. Lo usa `people:verify-register`; R2 lo
 * programará cada noche con aviso a los admins y el ancla diaria.
 */
final class RegisterIntegrity
{
    public function __construct(private readonly RegisterHasher $hasher) {}

    /**
     * @param  list<int>|null  $userIds  Solo esas personas (null: todas).
     * @return array{ok: bool, events: int, corrections: int, problems: list<string>}
     */
    public function verify(?array $userIds = null): array
    {
        $problems = [];
        $count = 0;
        /** @var array<int, array{seq: int, hash: string, ids: array<int, int>}> $chains */
        $chains = [];

        $query = ClockEvent::query()->orderBy('user_id')->orderBy('seq');

        if ($userIds !== null) {
            $query->whereIn('user_id', $userIds);
        }

        foreach ($query->lazy(500) as $event) {
            $count++;
            $chain = $chains[$event->user_id] ?? ['seq' => 0, 'hash' => RegisterHasher::GENESIS, 'ids' => []];
            $where = "persona {$event->user_id}, fila {$event->seq}";

            if ($event->seq !== $chain['seq'] + 1) {
                $problems[] = "{$where}: falta la fila ".($chain['seq'] + 1).' o sobra esta.';
            }

            if ($event->prev_hash !== $chain['hash']) {
                $problems[] = "{$where}: no enlaza con la fila anterior.";
            }

            if (! hash_equals($event->hash, $this->hasher->event($event))) {
                $problems[] = "{$where}: su contenido no coincide con su huella.";
            }

            if ($event->kind === ClockEventKind::Void
                && ($event->voided_event_id === null || ! isset($chain['ids'][$event->voided_event_id]))) {
                $problems[] = "{$where}: anula un fichaje que no es anterior ni de la misma persona.";
            }

            $chain['seq'] = $event->seq;
            $chain['hash'] = $event->hash;
            $chain['ids'][$event->id] = $event->seq;
            $chains[$event->user_id] = $chain;
        }

        $corrections = 0;
        $correctionQuery = ClockCorrection::query()->where('status', '!=', CorrectionStatus::Pending->value)->orderBy('id');

        if ($userIds !== null) {
            $correctionQuery->whereIn('user_id', $userIds);
        }

        foreach ($correctionQuery->lazy(500) as $correction) {
            $corrections++;

            if ($correction->hash === null || ! hash_equals($correction->hash, $this->hasher->correction($correction))) {
                $problems[] = "corrección {$correction->id}: su contenido no coincide con su sello.";
            }
        }

        return ['ok' => $problems === [], 'events' => $count, 'corrections' => $corrections, 'problems' => $problems];
    }
}
