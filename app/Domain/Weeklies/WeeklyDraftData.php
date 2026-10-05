<?php

namespace App\Domain\Weeklies;

/**
 * El contenido de una weekly (borrador o envío): un apunte por cliente, en orden.
 */
final readonly class WeeklyDraftData
{
    /**
     * @param  list<WeeklyEntryData>  $entries
     */
    public function __construct(public array $entries) {}

    /**
     * @param  array<array-key, mixed>  $entries  [{client_id, project_id, body, source}, …]
     */
    public static function fromArray(array $entries): self
    {
        return new self(array_values(array_map(
            fn (array $entry): WeeklyEntryData => WeeklyEntryData::fromArray($entry),
            array_filter($entries, is_array(...)),
        )));
    }

    /**
     * Apuntes con texto, uno por cliente (el último gana), en el orden recibido.
     *
     * @return list<WeeklyEntryData>
     */
    public function filled(): array
    {
        $byClient = [];

        foreach ($this->entries as $entry) {
            if (! $entry->isBlank()) {
                $byClient[$entry->clientId ?? 'general'] = $entry;
            }
        }

        return array_values($byClient);
    }

    public function isEmpty(): bool
    {
        return $this->filled() === [];
    }
}
