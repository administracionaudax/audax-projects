<?php

namespace App\Domain\Privacy\Export\Sections;

use App\Models\ClockCorrection;
use App\Models\User;

/**
 * Correcciones de mi registro de jornada (Fase 11; D-357): quién la propuso, el motivo, lo que
 * anula y añade, la decisión y la discrepancia.
 */
final class ClockCorrectionsSection extends Section
{
    public function key(): string
    {
        return 'correcciones-registro';
    }

    protected function textKey(): string
    {
        return 'clock_corrections';
    }

    protected function columnKeys(): array
    {
        return ['id', 'date', 'proposed_by', 'proposed_at', 'reason', 'voids', 'adds', 'status', 'decided_by', 'decided_at', 'decision_note', 'dispute_reason'];
    }

    public function rows(User $user): iterable
    {
        $corrections = ClockCorrection::query()->with(['proposer:id,name', 'decider:id,name'])->where('user_id', $user->id)->orderBy('date')->orderBy('id')->get();

        foreach ($corrections as $correction) {
            yield [
                'id' => $correction->id,
                'date' => self::date($correction->date),
                'proposed_by' => $correction->proposer->name,
                'proposed_at' => self::instant($correction->created_at),
                'reason' => $correction->reason,
                'voids' => (string) json_encode($correction->voids),
                'adds' => (string) json_encode($correction->adds, JSON_UNESCAPED_SLASHES),
                'status' => $correction->status->value,
                'decided_by' => $correction->decider?->name,
                'decided_at' => self::instant($correction->decided_at),
                'decision_note' => $correction->decision_note,
                'dispute_reason' => $correction->dispute_reason,
            ];
        }
    }
}
