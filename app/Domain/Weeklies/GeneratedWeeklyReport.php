<?php

namespace App\Domain\Weeklies;

use App\Domain\Weeklies\Report\WeeklyReport;

/**
 * Resultado de WeeklyReportGenerator::generate(): el informe estructurado, su texto final (Markdown,
 * para copiar, F-082) y cuántos envíos había al generarlo (para «Hay nuevos reportes», F-072).
 */
final readonly class GeneratedWeeklyReport
{
    public function __construct(
        public WeeklyReport $report,
        public string $text,
        public int $submissionCount,
        public string $model,
    ) {}
}
