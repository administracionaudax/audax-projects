<?php

namespace App\Enums;

/**
 * Estado de un cliente en el informe de la weekly (F-073 y F-075). WeeklySync usaba «On Track»,
 * «Risk» y «Blocked» dentro de structured_report: fromWeeklySync() lo convierte al importar.
 */
enum WeeklyClientStatus: string
{
    case OnTrack = 'on_track';
    case Risk = 'risk';
    case Blocked = 'blocked';

    public static function fromWeeklySync(string $value): self
    {
        return match (strtolower(trim($value))) {
            'blocked' => self::Blocked,
            'risk' => self::Risk,
            default => self::OnTrack,
        };
    }

    /** Gravedad para ordenar y para quedarse con el peor estado: on_track < risk < blocked. */
    public function severity(): int
    {
        return match ($this) {
            self::OnTrack => 0,
            self::Risk => 1,
            self::Blocked => 2,
        };
    }

    public function label(): string
    {
        return __("weeklies.enums.client_status.{$this->value}");
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
