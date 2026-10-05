<?php

namespace App\Domain\Weeklies\Report;

use App\Models\WeeklyCycle;

/**
 * Texto final del informe en Markdown (weekly_cycles.report_text): el que se copia (F-082) y el que
 * usa el guion del audio. Es el que escribía WeeklySync al editar el informe (`ws:App.tsx`
 * handleEditReport), ahora también al generarlo, para que el texto y el informe nunca difieran.
 */
final class WeeklyReportText
{
    public static function markdown(WeeklyCycle $cycle, WeeklyReport $report): string
    {
        $lines = ["# {$cycle->label}", '', '## Resumen Global', $report->globalSummary, ''];

        if ($report->teamRisks !== []) {
            $lines[] = '## ⚠️ Riesgos Detectados';

            foreach ($report->teamRisks as $risk) {
                $lines[] = "- {$risk}";
            }

            $lines[] = '';
        }

        $lines[] = '## Actualizaciones por Cliente';
        $lines[] = '';

        foreach ($report->clientUpdates as $update) {
            $lines[] = "### {$update->clientName} - ".$update->status->label();
            $lines[] = '';
            $lines[] = $update->executiveSummary;
            $lines[] = '';

            $steps = array_values(array_filter($update->nextSteps, fn (string $step): bool => trim($step) !== ''));
            if ($steps !== []) {
                $lines[] = '**Siguientes pasos:**';
                foreach ($steps as $step) {
                    $lines[] = "- {$step}";
                }
                $lines[] = '';
            }

            $milestones = array_values(array_filter($update->milestones, fn (WeeklyMilestone $milestone): bool => trim($milestone->label) !== ''));
            if ($milestones !== []) {
                $lines[] = '**Próximos hitos:**';
                foreach ($milestones as $milestone) {
                    $lines[] = '- '.($milestone->date !== null ? "{$milestone->date}: " : '').$milestone->label;
                }
                $lines[] = '';
            }
        }

        return rtrim(implode("\n", $lines))."\n";
    }
}
