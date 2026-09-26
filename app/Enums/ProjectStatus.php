<?php

namespace App\Enums;

/**
 * Estado de un proyecto (SPEC §4.2). Los proyectos no se borran: se archivan (D-037).
 */
enum ProjectStatus: string
{
    case Planned = 'planned';
    case Active = 'active';
    case OnHold = 'on_hold';
    case Completed = 'completed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planificado',
            self::Active => 'Activo',
            self::OnHold => 'En pausa',
            self::Completed => 'Completado',
            self::Archived => 'Archivado',
        };
    }

    /**
     * En un proyecto archivado no se imputa (SPEC §7).
     */
    public function acceptsTime(): bool
    {
        return $this !== self::Archived;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
