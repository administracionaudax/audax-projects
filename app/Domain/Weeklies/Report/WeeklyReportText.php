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

    /**
     * Secciones del texto final de una semana sin informe estructurado (las importadas de
     * WeeklySync, 10.9b): cada «## » abre una sección, como en el original; «# » y «### » son
     * subtítulos y el resto, párrafos. Sin las marcas de negrita.
     *
     * @return list<array{title: string|null, lines: list<array{kind: 'heading'|'subheading'|'text', text: string}>}>
     */
    public static function sections(string $text): array
    {
        $plain = fn (string $line): string => (string) preg_replace(['/\*\*(.+?)\*\*/u', '/__(.+?)__/u'], '$1', $line);
        $sections = [];
        $current = ['title' => null, 'lines' => []];

        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $raw) {
            $line = rtrim($raw);

            if (preg_match('/^##\s+(.+)$/u', $line, $match) === 1) {
                if ($current['title'] !== null || $current['lines'] !== []) {
                    $sections[] = $current;
                }

                $current = ['title' => $plain(trim($match[1])), 'lines' => []];

                continue;
            }

            if (preg_match('/^(#|#{3,6})\s+(.+)$/u', $line, $match) === 1) {
                $current['lines'][] = ['kind' => $match[1] === '#' ? 'heading' : 'subheading', 'text' => $plain(trim($match[2]))];

                continue;
            }

            if (trim($line) !== '') {
                $current['lines'][] = ['kind' => 'text', 'text' => $plain($line)];
            }
        }

        if ($current['title'] !== null || $current['lines'] !== []) {
            $sections[] = $current;
        }

        return $sections;
    }
}
