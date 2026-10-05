<?php

namespace App\Domain\Weeklies\Assistant;

use App\Domain\Weeklies\Report\ReportPipeline;

/**
 * El prompt del asistente (F-146 y F-147), portado de `query-knowledge-base` de WeeklySync: el mismo
 * contexto («CONTEXTO DE LA EMPRESA»), los mismos recortes (450 y 220 caracteres, 15 reportes y 30
 * tareas) y las mismas instrucciones. Con los datos de Audax se añaden (D-205): quién pregunta, el
 * último informe, el equipo, las horas que puede ver, los importes si los puede ver y la conversación
 * anterior de la sesión, más una instrucción para que diga que no tiene lo que no está en el
 * contexto. Funciones puras.
 */
final class AssistantPrompt
{
    public const int SHOWN_SUBMISSIONS = 15;

    public const int SHOWN_TASKS = 30;

    /** Turnos de la conversación anterior que se mandan, y su recorte. */
    public const int HISTORY_MESSAGES = 6;

    public const int HISTORY_CHARS = 600;

    /** `truncate` del original: corta y añade «...». Por caracteres (D-198). */
    public static function truncate(?string $value, int $max = 500): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max).'...' : $value;
    }

    /**
     * @param  array<string, mixed>  $context  AssistantContext::for()
     */
    public static function contextText(array $context): string
    {
        $lines = ['', 'CONTEXTO DE LA EMPRESA (Audax Studio):', ''];

        $lines[] = 'SEMANAS RECIENTES:';
        $lines[] = $context['weeks'] === [] ? '(Sin weeklies)' : implode("\n", array_map(
            fn (array $week): string => "- {$week['label']} ({$week['start']} a {$week['end']}): {$week['status']}, ".($week['has_report'] ? 'Reporte generado' : 'Sin reporte'),
            $context['weeks'],
        ));
        $lines[] = '';

        if ($context['report'] !== null) {
            $lines[] = "ÚLTIMO INFORME CERRADO ({$context['report']['label']}):";
            $lines[] = self::truncate($context['report']['global'], 600);
            foreach ($context['report']['clients'] as $client) {
                $lines[] = "- {$client['name']}: {$client['status']}. ".self::truncate($client['summary'], 220);
            }
            $lines[] = '';
        }

        $lines[] = 'CLIENTES ACTIVOS:';
        $lines[] = implode("\n", array_map(
            fn (array $client): string => "- {$client['name']} (Owner: ".($client['owner'] ?? 'N/A').", satisfacción {$client['satisfaction']}/100)",
            $context['clients'],
        ));
        $lines[] = '';

        $lines[] = 'EQUIPO:';
        $lines[] = implode("\n", array_map(
            fn (array $person): string => "- {$person['name']}".self::parenthesis([$person['job_title'], $person['department']]),
            $context['team'],
        ));
        $lines[] = '';

        $lines[] = 'REPORTES RECIENTES (con detalle por cliente):';
        $lines[] = implode("\n\n", array_map(function (array $submission): string {
            $content = self::truncate($submission['general'] ?? 'Sin contenido general', 450);

            if ($submission['entries'] !== []) {
                $content .= "\n".implode("\n", array_map(
                    fn (array $entry): string => "  * {$entry['client']}: ".self::truncate($entry['text'] !== '' ? $entry['text'] : 'Sin texto', 220),
                    $submission['entries'],
                ));
            }

            return "- {$submission['author']} en {$submission['week']}:\n{$content}";
        }, array_slice($context['submissions'], 0, self::SHOWN_SUBMISSIONS)));
        $lines[] = '';

        $lines[] = 'TAREAS ACTIVAS:';
        $lines[] = implode("\n", array_map(
            fn (array $task): string => "- [{$task['status']}] {$task['title']} (Asignada a: ".($task['assignee'] ?? 'N/A').', Cliente: '.($task['client'] ?? 'N/A').", Proyecto: {$task['project']}, Prioridad: {$task['priority']}".($task['due'] !== null ? ", Entrega: {$task['due']}" : '').')',
            array_slice($context['tasks'], 0, self::SHOWN_TASKS),
        ));
        $lines[] = '';

        $lines[] = 'ESTADO DE PROYECTOS (snapshot actual por cliente):';
        $lines[] = $context['projects'] === [] ? '(No hay estado de proyectos registrado aún)' : implode("\n", array_map(function (array $project): string {
            $consumed = (int) $project['consumed_minutes'];
            $budget = $project['budget_minutes'] === null ? 0 : (int) $project['budget_minutes'];
            $percent = $budget > 0 ? (int) round($consumed / $budget * 100) : 0;
            $expected = $project['expected_minutes'] !== null && $budget > 0
                ? '; esperado '.ReportPipeline::hours((int) $project['expected_minutes']).'h ('.(int) round((int) $project['expected_minutes'] / $budget * 100).'% del mes)'
                : '';
            $week = (int) ($project['week_minutes'] ?? 0) > 0 ? '; esta semana '.ReportPipeline::hours((int) $project['week_minutes']).'h' : '';

            return "- {$project['client']} · {$project['code']} ({$project['tag']}): ".ReportPipeline::hours($consumed).'h/'.ReportPipeline::hours($budget)."h consumidas ({$percent}% usado{$expected}{$week})";
        }, $context['projects']));
        $lines[] = '';

        $hours = match ($context['scope']['hours']) {
            'all' => 'de todo el equipo',
            'team' => 'tuyas y de tu equipo',
            default => 'solo las tuyas',
        };
        $lines[] = "HORAS REGISTRADAS (últimos 28 días, {$hours}):";
        $lines[] = $context['hours'] === [] ? '(Sin horas)' : implode("\n", array_map(
            fn (array $row): string => "- {$row['person']} · {$row['project']} (".($row['client'] ?? 'Interno').'): '.ReportPipeline::hours($row['minutes']).'h',
            $context['hours'],
        ));

        if ($context['amounts'] !== null) {
            $lines[] = '';
            $lines[] = 'IMPORTES DE VENTA (bolsas y proyectos):';
            $lines[] = $context['amounts'] === [] ? '(Sin importes)' : implode("\n", array_map(
                fn (array $row): string => "- {$row['label']}: ".($row['amount'] !== null ? "{$row['amount']} €" : 'sin precio').($row['rate'] !== null ? ", tarifa {$row['rate']} €/h" : ''),
                $context['amounts'],
            ));
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     */
    public static function prompt(string $contextText, string $question, string $asker, array $history = []): string
    {
        $previous = '';
        $history = array_slice($history, -self::HISTORY_MESSAGES);

        if ($history !== []) {
            $previous = "\nCONVERSACIÓN ANTERIOR (para entender la pregunta):\n".implode("\n", array_map(
                fn (array $message): string => ($message['role'] === 'assistant' ? 'Asistente: ' : 'Usuario: ').self::truncate($message['content'], self::HISTORY_CHARS),
                $history,
            ))."\n";
        }

        return <<<PROMPT

Eres un asistente de conocimiento empresarial para Audax Studio, una agencia digital.
Tu trabajo es responder preguntas sobre el histórico de proyectos, personas, reportes y estado de la empresa.

{$contextText}
QUIÉN PREGUNTA: {$asker}
{$previous}
PREGUNTA DEL USUARIO:
{$question}

INSTRUCCIONES:
- Responde de forma concisa y profesional
- Basa tu respuesta en el contexto proporcionado arriba (reportes, tareas, estado de proyectos, etc.)
- Si preguntan por el estado de un cliente, consulta los reportes recientes y el estado de proyectos actual
- Si preguntan por horas disponibles o consumidas, usa la sección "ESTADO DE PROYECTOS"
- Si no tienes información suficiente, dilo claramente
- Menciona fechas y nombres específicos cuando sea relevante
- Si la pregunta es sobre tendencias, analiza los datos históricos
- Si no hay datos relevantes, sugiere qué información podría ser útil
- El contexto es solo lo que esta persona puede ver: si preguntan por algo que no está (importes, costes, horas de otras personas…), di que no tienes esa información y no la inventes
- Formato: texto plano, sin markdown excepto listas con "-" si es necesario

Respuesta:

PROMPT;
    }

    /**
     * @param  list<string|null>  $parts
     */
    private static function parenthesis(array $parts): string
    {
        $parts = array_values(array_filter($parts, fn (?string $part): bool => $part !== null && $part !== ''));

        return $parts === [] ? '' : ' ('.implode(', ', $parts).')';
    }
}
