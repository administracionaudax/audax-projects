<?php

namespace App\Domain\People;

use LogicException;

/**
 * Intento de cambiar o borrar una fila del registro de jornada o una corrección ya decidida (D-332 y
 * D-335). Lo lanza el modelo antes de llegar a la base de datos, que además lo impide con un
 * *trigger*.
 */
final class RegisterImmutable extends LogicException
{
    public static function event(string $operation): self
    {
        return new self("El registro de jornada es de solo alta: no se puede {$operation} un fichaje (art. 34.9 ET).");
    }

    public static function correction(string $operation): self
    {
        return new self("Una corrección del registro no se puede {$operation} una vez decidida, ni borrar nunca.");
    }
}
