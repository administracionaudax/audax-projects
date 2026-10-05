<?php

namespace App\Domain\Weeklies\Report;

/**
 * Un cliente para el informe (groupClientInputs de `ws:generate-weekly-report/pipeline.js`): sus
 * apuntes enviados esa semana, en orden de envío, y el estado de sus proyectos (WeeklyProjectStatus).
 * clientId nulo = «General / Interno» (los apuntes sin cliente, D-189).
 */
final readonly class ReportClientInput
{
    /**
     * @param  list<WeeklyProjectSnapshot>  $projects
     * @param  list<ReportEntryInput>  $entries
     */
    public function __construct(
        public ?int $clientId,
        public string $clientName,
        public int $currentSatisfaction = 50,
        public array $projects = [],
        public array $entries = [],
    ) {}

    public function hasEntries(): bool
    {
        return $this->entries !== [];
    }

    /** «CONTEXTO DE ESTADO DE PROYECTOS» del prompt (buildProjectStatusContext). */
    public function financialContext(): string
    {
        return ReportPipeline::projectStatusContext($this->projects);
    }
}
