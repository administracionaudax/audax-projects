<?php

namespace App\Domain\Weeklies;

use App\Enums\WeeklyEntrySource;

/**
 * Un apunte de la weekly tal como llega del formulario «Mi weekly» (F-044 a F-052). clientId nulo =
 * «General / Interno».
 */
final readonly class WeeklyEntryData
{
    public function __construct(
        public ?int $clientId,
        public string $body,
        public ?int $projectId = null,
        public WeeklyEntrySource $source = WeeklyEntrySource::Text,
    ) {}

    /**
     * @param  array<string, mixed>  $data  {client_id, project_id, body, source}
     */
    public static function fromArray(array $data): self
    {
        $source = is_string($data['source'] ?? null) ? WeeklyEntrySource::tryFrom($data['source']) : null;

        return new self(
            clientId: is_numeric($data['client_id'] ?? null) ? (int) $data['client_id'] : null,
            body: is_string($data['body'] ?? null) ? $data['body'] : '',
            projectId: is_numeric($data['project_id'] ?? null) ? (int) $data['project_id'] : null,
            source: $source ?? WeeklyEntrySource::Text,
        );
    }

    /** ¿Tiene texto? Los apuntes vacíos no se guardan. */
    public function isBlank(): bool
    {
        return trim($this->body) === '';
    }
}
