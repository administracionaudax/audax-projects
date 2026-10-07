<?php

namespace App\Domain\Privacy\Export\Sections;

use App\Models\TimeBalanceMovement;
use App\Models\User;

/**
 * Movimientos de mi saldo de horas (Fase 11, R2; D-357).
 */
final class TimeBalanceSection extends Section
{
    public function key(): string
    {
        return 'saldo-horas';
    }

    protected function textKey(): string
    {
        return 'time_balance';
    }

    protected function columnKeys(): array
    {
        return ['date', 'kind', 'minutes', 'reason', 'created_by', 'created_at'];
    }

    public function rows(User $user): iterable
    {
        foreach (TimeBalanceMovement::query()->with('author:id,name')->where('user_id', $user->id)->orderBy('date')->orderBy('id')->get() as $movement) {
            yield [
                'date' => self::date($movement->date),
                'kind' => $movement->kind->value,
                'minutes' => $movement->minutes,
                'reason' => $movement->reason,
                'created_by' => $movement->author?->name,
                'created_at' => self::instant($movement->created_at),
            ];
        }
    }
}
