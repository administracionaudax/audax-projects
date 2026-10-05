<?php

namespace App\Domain\Weeklies\Report;

/**
 * Hito de un cliente en el informe (F-073 y F-077). date: "Y-m-d" o texto libre de la IA (WeeklySync
 * no lo normalizaba); null si no tiene.
 */
final readonly class WeeklyMilestone
{
    public function __construct(
        public ?string $date,
        public string $label,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $date = $data['date'] ?? null;

        return new self(
            date: is_scalar($date) && trim((string) $date) !== '' ? trim((string) $date) : null,
            label: trim(is_scalar($data['label'] ?? null) ? (string) $data['label'] : ''),
        );
    }

    /**
     * @return array{date: string|null, label: string}
     */
    public function toArray(): array
    {
        return ['date' => $this->date, 'label' => $this->label];
    }
}
