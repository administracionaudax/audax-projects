<?php

namespace App\Domain\People;

use App\Enums\ClockEventKind;
use App\Enums\CorrectionStatus;
use App\Models\ClockCorrection;
use App\Models\ClockEvent;
use App\Models\MonthClose;
use App\Models\OvertimeDecision;
use App\Models\RegisterAnchor;
use App\Models\RegisterCheckpoint;
use App\Models\TimeBalanceMovement;
use Illuminate\Support\Facades\Storage;

/**
 * Comprueba que el registro no se ha tocado (PLAN-FASE-11 §11.1; D-332 y D-352):
 *
 * - por cada persona, que la cadena va de 1 en 1 sin huecos desde su punto de partida (1, o el
 *   punto de control tras la supresión del mes 49), que cada fila guarda la huella de la anterior y
 *   que su propia huella coincide con su contenido; y que cada anulación apunta a un fichaje
 *   anterior de la misma persona;
 * - que cada corrección decidida, decisión de horas extra y movimiento del saldo conserva su sello;
 * - que lo congelado de cada cierre mensual coincide con su sello y su PDF con su SHA-256;
 * - que las anclas diarias se encadenan y que la fila que cada una recuerda sigue teniendo la misma
 *   huella (si no se ha suprimido).
 *
 * Lo usan `people:verify-register` (cada noche, con el ancla y el aviso a los admins) y la pantalla
 * de la Inspección.
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

        foreach (RegisterCheckpoint::query()->when($userIds !== null, fn ($query) => $query->whereIn('user_id', $userIds))->get() as $checkpoint) {
            $chains[$checkpoint->user_id] = ['seq' => $checkpoint->seq, 'hash' => $checkpoint->hash, 'ids' => []];
        }

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

        foreach (OvertimeDecision::query()->when($userIds !== null, fn ($q) => $q->whereIn('user_id', $userIds))->orderBy('id')->lazy(500) as $decision) {
            if (! hash_equals($decision->hash, $this->hasher->overtimeDecision($decision))) {
                $problems[] = "horas extra {$decision->id}: su contenido no coincide con su sello.";
            }
        }

        foreach (TimeBalanceMovement::query()->when($userIds !== null, fn ($q) => $q->whereIn('user_id', $userIds))->orderBy('id')->lazy(500) as $movement) {
            if (! hash_equals($movement->hash, $this->hasher->balanceMovement($movement))) {
                $problems[] = "saldo de horas {$movement->id}: su contenido no coincide con su sello.";
            }
        }

        $disk = Storage::disk(MonthCloser::DISK);

        foreach (MonthClose::query()->when($userIds !== null, fn ($q) => $q->whereIn('user_id', $userIds))->orderBy('id')->lazy(200) as $close) {
            $where = "cierre de {$close->monthKey()} (persona {$close->user_id}, versión {$close->version})";

            if (! hash_equals($close->content_hash, $this->hasher->closeContent($close))) {
                $problems[] = "{$where}: lo congelado no coincide con su sello.";
            }

            if (! $disk->exists($close->pdf_path)) {
                $problems[] = "{$where}: falta su PDF.";
            } elseif (! hash_equals($close->pdf_sha256, hash('sha256', (string) $disk->get($close->pdf_path)))) {
                $problems[] = "{$where}: su PDF no coincide con su huella.";
            }
        }

        if ($userIds === null) {
            $problems = [...$problems, ...$this->anchors()];
        }

        return ['ok' => $problems === [], 'events' => $count, 'corrections' => $corrections, 'problems' => $problems];
    }

    /**
     * Las anclas se encadenan y las filas que recuerdan no han cambiado.
     *
     * @return list<string>
     */
    private function anchors(): array
    {
        $problems = [];
        $previous = null;
        $checkpoints = RegisterCheckpoint::query()->pluck('seq', 'user_id')->all();

        foreach (RegisterAnchor::query()->orderBy('date')->lazy(200) as $anchor) {
            $date = $anchor->date->toDateString();

            if ($previous !== null && $anchor->prev_digest !== $previous) {
                $problems[] = "ancla del {$date}: no enlaza con la anterior.";
            }

            if (! hash_equals($anchor->digest, $this->hasher->anchorDigest($date, $anchor->prev_digest, $anchor->events_count, $anchor->heads))) {
                $problems[] = "ancla del {$date}: su contenido no coincide con su resumen.";
            }

            foreach ($anchor->heads as $userId => $head) {
                if (($checkpoints[(int) $userId] ?? 0) >= $head['seq']) {
                    continue;
                }

                $hash = ClockEvent::query()->where('user_id', (int) $userId)->where('seq', $head['seq'])->value('hash');

                if ($hash !== $head['hash']) {
                    $problems[] = "ancla del {$date}: la fila {$head['seq']} de la persona {$userId} ya no es la que se ancló.";
                }
            }

            $previous = $anchor->digest;
        }

        return $problems;
    }
}
