<?php

namespace App\Domain\System;

use Illuminate\Container\Attributes\Bind;

/**
 * Mide el disco que contiene una ruta (D-076). Es una interfaz para poder simular un disco lleno en
 * los tests: $this->app->instance(DiskUsage::class, …).
 */
#[Bind(NativeDiskUsage::class)]
interface DiskUsage
{
    /** Espacio del disco de $path, o null si no se puede medir. */
    public function measure(string $path): ?DiskSpace;
}
