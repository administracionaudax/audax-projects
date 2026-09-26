<?php

namespace App\Domain\Time;

use App\Models\TimeEntry;

final readonly class TimeEntryResult
{
    /**
     * @param  list<TimeEntryWarning>  $warnings
     */
    public function __construct(
        public TimeEntry $entry,
        public array $warnings = [],
    ) {}

    /**
     * @return list<array{code: string, message: string}>
     */
    public function warningsArray(): array
    {
        return array_map(fn (TimeEntryWarning $warning): array => $warning->toArray(), $this->warnings);
    }
}
