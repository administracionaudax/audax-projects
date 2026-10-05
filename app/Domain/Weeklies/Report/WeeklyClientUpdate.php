<?php

namespace App\Domain\Weeklies\Report;

use App\Enums\WeeklyClientStatus;

/**
 * Bloque de un cliente en el informe (F-073 a F-077), de `ws:types.ts` (WeeklyClientUpdate) en
 * snake_case. client_id nulo = sin cliente de Audax (no debería pasar salvo en importaciones).
 * has_reports = false: nadie escribió de él esa semana («Sin novedades», F-075).
 */
final readonly class WeeklyClientUpdate
{
    /**
     * @param  list<string>  $nextSteps
     * @param  list<WeeklyMilestone>  $milestones
     * @param  list<string>  $tags
     * @param  list<WeeklyProjectSnapshot>  $projects
     */
    public function __construct(
        public ?int $clientId,
        public string $clientName,
        public WeeklyClientStatus $status,
        public string $executiveSummary,
        public array $nextSteps = [],
        public array $milestones = [],
        public array $tags = [],
        public ?int $satisfactionScore = null,
        public bool $hasReports = true,
        public array $projects = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $status = is_string($data['status'] ?? null) ? WeeklyClientStatus::tryFrom($data['status']) : null;

        return new self(
            clientId: is_numeric($data['client_id'] ?? null) ? (int) $data['client_id'] : null,
            clientName: (string) ($data['client_name'] ?? ''),
            status: $status ?? WeeklyClientStatus::OnTrack,
            executiveSummary: (string) ($data['executive_summary'] ?? ''),
            nextSteps: self::strings($data['next_steps'] ?? []),
            milestones: array_values(array_map(
                fn (array $milestone): WeeklyMilestone => WeeklyMilestone::fromArray($milestone),
                array_filter(is_array($data['milestones'] ?? null) ? $data['milestones'] : [], is_array(...)),
            )),
            tags: self::strings($data['tags'] ?? []),
            satisfactionScore: is_numeric($data['satisfaction_score'] ?? null) ? (int) $data['satisfaction_score'] : null,
            hasReports: (bool) ($data['has_reports'] ?? true),
            projects: array_values(array_map(
                fn (array $project): WeeklyProjectSnapshot => WeeklyProjectSnapshot::fromArray($project),
                array_filter(is_array($data['projects'] ?? null) ? $data['projects'] : [], is_array(...)),
            )),
        );
    }

    /**
     * Desde un clientUpdates[] de WeeklySync (importación, 10.8).
     *
     * @param  array<string, mixed>  $data
     * @param  callable(string|null, string): (int|null)  $clientId  id de WeeklySync y nombre → id de Audax
     */
    public static function fromWeeklySync(array $data, callable $clientId): self
    {
        $name = (string) ($data['clientName'] ?? '');

        return self::fromArray([
            'client_id' => $clientId(isset($data['clientId']) ? (string) $data['clientId'] : null, $name),
            'client_name' => $name,
            'status' => WeeklyClientStatus::fromWeeklySync((string) ($data['status'] ?? '')),
            'executive_summary' => $data['executiveSummary'] ?? '',
            'next_steps' => $data['nextSteps'] ?? [],
            'milestones' => $data['milestones'] ?? [],
            'tags' => $data['tags'] ?? [],
            'satisfaction_score' => $data['satisfactionScore'] ?? null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'client_id' => $this->clientId,
            'client_name' => $this->clientName,
            'status' => $this->status->value,
            'executive_summary' => $this->executiveSummary,
            'next_steps' => $this->nextSteps,
            'milestones' => array_map(fn (WeeklyMilestone $m): array => $m->toArray(), $this->milestones),
            'tags' => $this->tags,
            'satisfaction_score' => $this->satisfactionScore,
            'has_reports' => $this->hasReports,
            'projects' => array_map(fn (WeeklyProjectSnapshot $p): array => $p->toArray(), $this->projects),
        ];
    }

    public function withSatisfaction(?int $score): self
    {
        return new self($this->clientId, $this->clientName, $this->status, $this->executiveSummary, $this->nextSteps, $this->milestones, $this->tags, $score, $this->hasReports, $this->projects);
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '', $values),
            fn (string $value): bool => $value !== '',
        ));
    }
}
