<?php

namespace App\Enums;

/**
 * Tipo de conversación (SPEC §12): de proyecto (una por proyecto, con sus miembros), directa 1:1 o de grupo.
 */
enum ConversationType: string
{
    case Project = 'project';
    case Direct = 'direct';
    case Group = 'group';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
