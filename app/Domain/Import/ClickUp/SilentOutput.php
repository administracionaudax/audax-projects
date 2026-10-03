<?php

namespace App\Domain\Import\ClickUp;

/**
 * Salida vacía (tests y llamadas sin consola).
 */
final class SilentOutput implements ImportOutput
{
    public function stage(string $label): void {}

    public function progressStart(int $max): void {}

    public function progressAdvance(int $steps = 1): void {}

    public function progressFinish(): void {}
}
