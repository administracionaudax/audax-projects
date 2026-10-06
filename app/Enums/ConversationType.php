<?php

namespace App\Enums;

/**
 * Tipo de conversación (SPEC §12): de proyecto (una por proyecto, con sus miembros), directa 1:1 o
 * de grupo; y los canales (D-270 a D-272): el de cada cliente (lo ve quien ve sus proyectos) y los
 * de equipo (abiertos a toda la plantilla interna).
 */
enum ConversationType: string
{
    case Project = 'project';
    case Direct = 'direct';
    case Group = 'group';
    case Client = 'client';
    case Team = 'team';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Canal (de cliente o de equipo): se ve por regla, no solo por participar; se entra y se sale
     * sin mensajes de sistema y quien escribe pasa a participar.
     */
    public function isChannel(): bool
    {
        return $this === self::Client || $this === self::Team;
    }

    /**
     * @return list<string>
     */
    public static function channelValues(): array
    {
        return [self::Client->value, self::Team->value];
    }
}
