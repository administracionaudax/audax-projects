<?php

namespace App\Domain\Weeklies;

use App\Enums\WeeklyCycleStatus;
use Carbon\CarbonImmutable;

/**
 * Una semana de la racha de una persona (entrada de StreakCalculator).
 *
 * @param  bool  $required  false si se dio de alta después del final de la semana (no cuenta)
 */
final readonly class StreakWeek
{
    public function __construct(
        public CarbonImmutable $endDate,
        public CarbonImmutable $deadlineDate,
        public WeeklyCycleStatus $status,
        public bool $exempt,
        public ?CarbonImmutable $submittedAt,
        public bool $required = true,
    ) {}
}
