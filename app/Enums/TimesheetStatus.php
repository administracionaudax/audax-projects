<?php

namespace App\Enums;

/**
 * Estado de la semana de un usuario (SPEC §4.4 y D-020). Solo en `open` y `returned`
 * se pueden crear, editar o borrar entradas (SPEC §7; el admin puede editar bloqueadas).
 */
enum TimesheetStatus: string
{
    case Open = 'open';
    case Submitted = 'submitted';
    case Returned = 'returned';
    case Approved = 'approved';
    case Locked = 'locked';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Abierta',
            self::Submitted => 'Enviada',
            self::Returned => 'Devuelta',
            self::Approved => 'Aprobada',
            self::Locked => 'Bloqueada',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Open || $this === self::Returned;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
