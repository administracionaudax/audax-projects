<?php

namespace App\Domain\Privacy\Export\Sections;

use App\Models\OvertimeDecision;
use App\Models\User;

/**
 * Clasificación de mis horas extra (Fase 11, R2; D-357), también las decisiones sustituidas.
 */
final class OvertimeSection extends Section
{
    public function key(): string
    {
        return 'horas-extra';
    }

    protected function textKey(): string
    {
        return 'overtime';
    }

    protected function columnKeys(): array
    {
        return ['date', 'hour_type', 'excess_minutes', 'overtime_minutes', 'flex_minutes', 'destination', 'decided_by', 'decided_at', 'supersedes_id', 'note'];
    }

    public function rows(User $user): iterable
    {
        foreach (OvertimeDecision::query()->with('decider:id,name')->where('user_id', $user->id)->orderBy('date')->orderBy('id')->get() as $decision) {
            yield [
                'date' => self::date($decision->date),
                'hour_type' => $decision->hour_type->value,
                'excess_minutes' => $decision->excess_minutes,
                'overtime_minutes' => $decision->overtime_minutes,
                'flex_minutes' => $decision->flex_minutes,
                'destination' => $decision->destination?->value,
                'decided_by' => $decision->decider->name,
                'decided_at' => self::instant($decision->created_at),
                'supersedes_id' => $decision->supersedes_id,
                'note' => $decision->note,
            ];
        }
    }
}
