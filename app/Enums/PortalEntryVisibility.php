<?php

namespace App\Enums;

/**
 * Qué horas ve el cliente (SPEC §11, D-064): por defecto solo las aprobadas o bloqueadas; se puede
 * ampliar a las enviadas. Los borradores nunca.
 */
enum PortalEntryVisibility: string
{
    case Approved = 'approved';
    case Submitted = 'submitted';

    /**
     * @return list<string>
     */
    public function statuses(): array
    {
        return match ($this) {
            self::Approved => [TimeEntryStatus::Approved->value, TimeEntryStatus::Locked->value],
            self::Submitted => [TimeEntryStatus::Submitted->value, TimeEntryStatus::Approved->value, TimeEntryStatus::Locked->value],
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
