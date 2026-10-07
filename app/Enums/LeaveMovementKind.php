<?php

namespace App\Enums;

/**
 * Movimientos del libro de saldos de ausencias (Fase 11, R3; D-363). Solo alta: un saldo nunca se
 * edita, solo se añaden movimientos.
 * - `accrual`: la asignación anual (y sus recálculos por la fecha de alta o de baja o un cambio del
 *   tipo, con el signo que toque),
 * - `adjustment`: ajuste manual con motivo (±), solo RR. HH.,
 * - `opening_balance`: saldo inicial (lo que venga de Woffu en R5, ±), solo RR. HH.,
 * - `carry_over`: arrastre a otra fecha de caducidad (p. ej. por una IT o un nacimiento, art. 38.3
 *   ET): un cargo en lo que caducaba y un abono con la caducidad nueva.
 */
enum LeaveMovementKind: string
{
    case Accrual = 'accrual';
    case Adjustment = 'adjustment';
    case OpeningBalance = 'opening_balance';
    case CarryOver = 'carry_over';

    /**
     * @return list<self>
     */
    public static function manual(): array
    {
        return [self::Adjustment, self::OpeningBalance];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
