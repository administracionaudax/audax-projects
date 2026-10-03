<?php

namespace App\Domain\Import\ClickUp;

/**
 * Salida del progreso de la importación (la consola en el comando; nada en los tests).
 */
interface ImportOutput
{
    public function stage(string $label): void;

    public function progressStart(int $max): void;

    public function progressAdvance(int $steps = 1): void;

    public function progressFinish(): void;
}
