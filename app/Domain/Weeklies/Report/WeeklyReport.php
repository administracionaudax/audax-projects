<?php

namespace App\Domain\Weeklies\Report;

/**
 * Informe estructurado de una semana (F-073), guardado en weekly_cycles.report. Es el
 * WeeklyStructuredReport de `ws:types.ts` en snake_case; las secciones de audio van en su propia tabla
 * (weekly_audio_sections). Contrato TS: resources/js/types/weeklies.ts (WeeklyReport).
 */
final readonly class WeeklyReport
{
    /**
     * @param  list<string>  $teamRisks
     * @param  list<WeeklyClientUpdate>  $clientUpdates
     */
    public function __construct(
        public string $globalSummary,
        public array $teamRisks = [],
        public array $clientUpdates = [],
    ) {}

    /**
     * Tolerante: lo que falta toma su valor por defecto y lo que no tiene forma se descarta.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $risks = is_array($data['team_risks'] ?? null) ? $data['team_risks'] : [];
        $updates = is_array($data['client_updates'] ?? null) ? $data['client_updates'] : [];

        return new self(
            globalSummary: (string) ($data['global_summary'] ?? ''),
            teamRisks: array_values(array_filter(array_map(fn (mixed $risk): string => is_scalar($risk) ? trim((string) $risk) : '', $risks), fn (string $risk): bool => $risk !== '')),
            clientUpdates: array_values(array_map(
                fn (array $update): WeeklyClientUpdate => WeeklyClientUpdate::fromArray($update),
                array_filter($updates, is_array(...)),
            )),
        );
    }

    /**
     * Desde structured_report de WeeklySync (importación, 10.8). Las audioSections se importan
     * aparte, a weekly_audio_sections.
     *
     * @param  array<string, mixed>  $data
     * @param  callable(string|null, string): (int|null)  $clientId  id de WeeklySync y nombre → id de Audax
     */
    public static function fromWeeklySync(array $data, callable $clientId): self
    {
        $updates = is_array($data['clientUpdates'] ?? null) ? $data['clientUpdates'] : [];

        return new self(
            globalSummary: (string) ($data['globalSummary'] ?? ''),
            teamRisks: self::fromArray(['team_risks' => $data['teamRisks'] ?? []])->teamRisks,
            clientUpdates: array_values(array_map(
                fn (array $update): WeeklyClientUpdate => WeeklyClientUpdate::fromWeeklySync($update, $clientId),
                array_filter($updates, is_array(...)),
            )),
        );
    }

    /**
     * @return array{global_summary: string, team_risks: list<string>, client_updates: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'global_summary' => $this->globalSummary,
            'team_risks' => $this->teamRisks,
            'client_updates' => array_map(fn (WeeklyClientUpdate $update): array => $update->toArray(), $this->clientUpdates),
        ];
    }

    public function clientUpdate(int $clientId): ?WeeklyClientUpdate
    {
        foreach ($this->clientUpdates as $update) {
            if ($update->clientId === $clientId) {
                return $update;
            }
        }

        return null;
    }
}
