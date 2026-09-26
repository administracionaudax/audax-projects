<?php

namespace App\Domain\HourBanks\Events;

use App\Models\HourBank;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Una bolsa ha alcanzado un umbral de consumo (SPEC §8.5). Se emite una sola vez por umbral y
 * bolsa (D-035); si una imputación cruza varios umbrales a la vez, solo el más alto.
 */
final class HourBankThresholdReached implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly HourBank $hourBank,
        public readonly int $threshold,
    ) {}
}
