<?php

namespace App\Domain\Weeklies\Ai;

use RuntimeException;

/**
 * Una persona ha llegado a su límite diario de IA (D-222). El mensaje es para ella.
 */
final class AiDailyLimitReached extends RuntimeException
{
    public function __construct(public readonly string $bucket, public readonly int $limit)
    {
        parent::__construct(__("weeklies.ai_limits.{$bucket}", ['limit' => $limit]));
    }
}
