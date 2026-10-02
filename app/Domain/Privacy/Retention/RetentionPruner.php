<?php

namespace App\Domain\Privacy\Retention;

use Carbon\CarbonImmutable;

/**
 * Borra un tipo de dato anterior a su plazo de retención (D-075). Cada tipo de
 * RetentionPolicy::SETTINGS tiene el suyo en config/privacy.php (pruners); al integrar la Fase 6 se
 * añade el de los mensajes del chat. Borra por lotes cortos (BatchDelete): nunca un DELETE enorme
 * que bloquee la tabla.
 */
interface RetentionPruner
{
    /** Filas borradas. */
    public function prune(CarbonImmutable $cutoff, int $batchSize): int;
}
