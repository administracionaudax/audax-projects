<?php

namespace App\Enums;

/**
 * Movimientos del saldo de horas (PLAN-FASE-11 §7.2; D-350). Se llama «saldo de horas», nunca
 * «bolsa», para no confundirlo con las bolsas de horas de los clientes.
 * - `overtime`: horas extra que se compensan con descanso (+, 80 minutos por hora),
 * - `rest_taken`: descanso disfrutado (−),
 * - `paid`: saldo que se paga en nómina (−),
 * - `adjustment`: ajuste con motivo (±), también la reversión de una decisión sustituida,
 * - `opening_balance`: saldo inicial (p. ej. el que venga de Woffu, ±).
 */
enum BalanceMovementKind: string
{
    case Overtime = 'overtime';
    case RestTaken = 'rest_taken';
    case Paid = 'paid';
    case Adjustment = 'adjustment';
    case OpeningBalance = 'opening_balance';

    public function label(): string
    {
        return __("people.balance.kinds.{$this->value}");
    }

    /**
     * Los que se registran a mano (el de horas extra lo escribe la decisión).
     *
     * @return list<self>
     */
    public static function manual(): array
    {
        return [self::RestTaken, self::Paid, self::Adjustment, self::OpeningBalance];
    }

    /** Signo obligatorio: −1, +1 o 0 (cualquiera). */
    public function sign(): int
    {
        return match ($this) {
            self::Overtime => 1,
            self::RestTaken, self::Paid => -1,
            self::Adjustment, self::OpeningBalance => 0,
        };
    }
}
