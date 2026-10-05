<?php

namespace App\Domain\Weeklies\Satisfaction;

/**
 * Resultado de SatisfactionStabilizer::stabilize(): el delta final (de −8 a +8), la regla que lo
 * decidió y las señales del texto. Reglas: zero-or-invalid-request, too-little-information,
 * no-explicit-client-impact, routine-week-no-change, guarded-to-stable, dampened y accepted.
 */
final readonly class SatisfactionDecision
{
    public function __construct(
        public int $finalDelta,
        public string $rule,
        public SignalMetrics $metrics,
    ) {}

    /** Nueva puntuación acotada a 0-100 (`close-week…:208`). */
    public function applyTo(int $currentScore): int
    {
        return max(0, min(100, $currentScore + $this->finalDelta));
    }

    /**
     * Mismo orden de claves que el objeto del original.
     *
     * @return array{finalDelta: int, metrics: array<string, int|bool>, rule: string}
     */
    public function toArray(): array
    {
        return ['finalDelta' => $this->finalDelta, 'metrics' => $this->metrics->toArray(), 'rule' => $this->rule];
    }
}
