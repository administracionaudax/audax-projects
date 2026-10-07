<?php

namespace App\Domain\Privacy\Export\Sections;

use App\Models\LeaveMovement;
use App\Models\User;

/**
 * Movimientos de mis saldos de vacaciones y permisos (Fase 11, R3; D-372): asignaciones, ajustes,
 * saldos iniciales y arrastres, con su caducidad y el motivo.
 */
final class LeaveBalanceSection extends Section
{
    public function key(): string
    {
        return 'saldos-ausencias';
    }

    protected function textKey(): string
    {
        return 'leave_balance';
    }

    protected function columnKeys(): array
    {
        return ['type', 'year', 'kind', 'amount', 'unit', 'valid_from', 'expires_on', 'reason', 'created_by', 'created_at'];
    }

    public function rows(User $user): iterable
    {
        foreach (LeaveMovement::query()->with(['author:id,name', 'leaveType:id,name,unit'])->where('user_id', $user->id)->orderBy('created_at')->orderBy('id')->get() as $movement) {
            yield [
                'type' => $movement->leaveType->name,
                'year' => $movement->year,
                'kind' => $movement->kind->value,
                'amount' => $movement->amount,
                'unit' => $movement->leaveType->unit->value,
                'valid_from' => self::date($movement->valid_from),
                'expires_on' => self::date($movement->expires_on),
                'reason' => $movement->reason,
                'created_by' => $movement->author?->name,
                'created_at' => self::instant($movement->created_at),
            ];
        }
    }
}
