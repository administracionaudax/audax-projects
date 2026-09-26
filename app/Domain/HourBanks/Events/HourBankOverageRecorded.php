<?php

namespace App\Domain\HourBanks\Events;

use App\Models\HourBank;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se han registrado horas en exceso sobre una bolsa agotada (SPEC §8.6). Como máximo una vez
 * al día por bolsa (D-035).
 */
final class HourBankOverageRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly HourBank $hourBank,
        public readonly int $addedOverageMinutes,
    ) {}
}
