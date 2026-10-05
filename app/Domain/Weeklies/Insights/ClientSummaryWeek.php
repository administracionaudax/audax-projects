<?php

namespace App\Domain\Weeklies\Insights;

use App\Domain\Weeklies\Report\WeeklyClientUpdate;

/**
 * Una semana del histórico del resumen del cliente (buildRelevantClientWeeks de WeeklySync): su
 * etiqueta, el último envío, los reportes de cada persona (recortados) y lo que dijo el informe.
 */
final class ClientSummaryWeek
{
    /**
     * @param  list<array{authorName: string, submittedAt: string, text: string}>  $rawEntries
     */
    public function __construct(
        public readonly int $cycleId,
        public readonly string $weekLabel,
        public readonly string $weekEndDate,
        public string $submittedAt,
        public array $rawEntries,
        public readonly ?WeeklyClientUpdate $update,
    ) {}
}
