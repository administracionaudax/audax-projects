<?php

namespace App\Domain\System;

/**
 * Medición real con disk_total_space y disk_free_space de PHP.
 */
final class NativeDiskUsage implements DiskUsage
{
    public function measure(string $path): ?DiskSpace
    {
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        if ($total === false || $free === false || $total <= 0) {
            return null;
        }

        return new DiskSpace((int) $total, (int) $free);
    }
}
