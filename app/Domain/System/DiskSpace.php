<?php

namespace App\Domain\System;

/**
 * Tamaño y espacio libre de un disco, en bytes.
 */
final readonly class DiskSpace
{
    public function __construct(
        public int $totalBytes,
        public int $freeBytes,
    ) {}

    public function usedBytes(): int
    {
        return max(0, $this->totalBytes - $this->freeBytes);
    }

    /** Porcentaje usado (0–100), con un decimal. */
    public function usedPercent(): float
    {
        return $this->totalBytes <= 0 ? 0.0 : round($this->usedBytes() * 100 / $this->totalBytes, 1);
    }
}
