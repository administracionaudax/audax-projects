<?php

namespace App\Domain\Weeklies\Satisfaction;

/**
 * Señales del texto de las weeklies de un cliente (getSignalMetrics de
 * `ws:supabase/functions/_shared/satisfaction.js`). toArray() usa las claves del original para
 * compararlo con los fixtures compartidos (tests/fixtures/weeklies/satisfaction-cases.json).
 */
final readonly class SignalMetrics
{
    public function __construct(
        public int $wordCount,
        public int $positiveSignals,
        public int $negativeSignals,
        public int $routineSignals,
        public int $explicitPositiveSignals,
        public int $explicitNegativeSignals,
        public int $severeNegativeSignals,
        public int $deliveredValueSignals,
    ) {}

    public function balance(): int
    {
        return $this->positiveSignals - $this->negativeSignals;
    }

    public function hasExplicitSignal(): bool
    {
        return $this->explicitPositiveSignals > 0 || $this->explicitNegativeSignals > 0;
    }

    public function hasPositiveChangeSignal(): bool
    {
        return $this->explicitPositiveSignals > 0 || $this->deliveredValueSignals > 0;
    }

    public function hasNegativeChangeSignal(): bool
    {
        return $this->explicitNegativeSignals > 0 || $this->severeNegativeSignals > 0 || $this->negativeSignals >= 2;
    }

    public function hasMixedSignals(): bool
    {
        return $this->positiveSignals > 0 && $this->negativeSignals > 0;
    }

    /**
     * @return array<string, int|bool>
     */
    public function toArray(): array
    {
        return [
            'wordCount' => $this->wordCount,
            'positiveSignals' => $this->positiveSignals,
            'negativeSignals' => $this->negativeSignals,
            'routineSignals' => $this->routineSignals,
            'explicitPositiveSignals' => $this->explicitPositiveSignals,
            'explicitNegativeSignals' => $this->explicitNegativeSignals,
            'severeNegativeSignals' => $this->severeNegativeSignals,
            'deliveredValueSignals' => $this->deliveredValueSignals,
            'balance' => $this->balance(),
            'hasExplicitSignal' => $this->hasExplicitSignal(),
            'hasPositiveChangeSignal' => $this->hasPositiveChangeSignal(),
            'hasNegativeChangeSignal' => $this->hasNegativeChangeSignal(),
            'hasMixedSignals' => $this->hasMixedSignals(),
        ];
    }
}
