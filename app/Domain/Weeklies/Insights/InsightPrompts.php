<?php

namespace App\Domain\Weeklies\Insights;

use App\Domain\Weeklies\ProjectStatus\ProjectKindCode;
use App\Domain\Weeklies\Report\ReportPipeline;
use App\Domain\Weeklies\Report\WeeklyClientUpdate;
use App\Enums\WeeklyClientStatus;

/**
 * Los prompts de las fichas de cliente y de persona, portados palabra por palabra de las Edge
 * Functions de WeeklySync (D-194): `generate-client-summary` (pipeline.js), `analyze-team-activity`,
 * `generate-performance-summary` y `analyze-user-client-activity` (pipeline.js). Funciones puras:
 * reciben los datos ya reunidos (InsightData) y devuelven el texto que va a Gemini.
 *
 * Diferencias anotadas:
 * - los recortes cuentan caracteres (mb_*), no unidades UTF-16 de JavaScript: solo cambia con emojis,
 * - los identificadores son los de Audax (números, como texto en el JSON),
 * - el estado de proyectos sale de ProjectStatusBoard (D-148) y el tipo, de ProjectKindCode.
 */
final class InsightPrompts
{
    public const string INSUFFICIENT_CLIENT_HISTORY = 'No hay suficiente historial del cliente para generar un resumen.';

    public const string INSUFFICIENT_PERFORMANCE_HISTORY = 'No hay suficiente historial de reportes para generar un resumen de desempeño.';

    public const string NO_ACTIVITY = 'Sin actividad reciente detectada en reportes para este cliente.';

    public const string ACTIVITY_NOT_SUMMARIZED = 'Actividad detectada, pero no se pudo resumir correctamente.';

    public const int CLIENT_RAW_ENTRY_MAX_LENGTH = 420;

    public const int CLIENT_STRUCTURED_SUMMARY_MAX_LENGTH = 520;

    public const int CLIENT_MAX_RELEVANT_WEEKS = 10;

    public const int CLIENT_MAX_ENTRY_ROWS = 500;

    public const int TEAM_ACTIVITY_MAX_ENTRY_ROWS = 100;

    public const int TEAM_ACTIVITY_MAX_REPORTS = 6;

    public const int PERFORMANCE_MAX_SUBMISSIONS = 8;

    public const int PERSON_ACTIVITY_MAX_SUBMISSIONS = 80;

    public const int PERSON_ACTIVITY_MAX_REPORTS = 6;

    public const int PERSON_ACTIVITY_MAX_ENTRY_LENGTH = 320;

    // --- Utilidades -----------------------------------------------------------------------------

    /** truncate de generate-client-summary: espacios normalizados y «…» al cortar. */
    public static function truncateEllipsis(mixed $value, int $max): string
    {
        $normalized = ReportPipeline::normalizeWhitespace($value);

        if ($normalized === '') {
            return '';
        }

        return mb_strlen($normalized) > $max ? trim(mb_substr($normalized, 0, $max)).'…' : $normalized;
    }

    /** truncate de analyze-team-activity y generate-performance-summary: tal cual y «...» al cortar. */
    public static function truncateRaw(?string $value, int $max): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max).'...' : $value;
    }

    /** truncate de analyze-user-client-activity: espacios normalizados y «...» al cortar. */
    public static function truncateDots(mixed $value, int $max): string
    {
        $normalized = ReportPipeline::normalizeWhitespace($value);

        if ($normalized === '') {
            return '';
        }

        return mb_strlen($normalized) > $max ? trim(mb_substr($normalized, 0, $max)).'...' : $normalized;
    }

    /** stripCodeFences + trim (parseSummaryResponse). */
    public static function stripCodeFences(string $value): string
    {
        $trimmed = trim($value);

        if (str_starts_with($trimmed, '```json')) {
            return trim((string) preg_replace(['/^```json\s*/i', '/```$/i'], '', $trimmed));
        }

        if (str_starts_with($trimmed, '```')) {
            return trim((string) preg_replace(['/^```[a-z]*\s*/i', '/```$/i'], '', $trimmed));
        }

        return $trimmed;
    }

    public static function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Estado del informe con las palabras de WeeklySync (On Track, Risk o Blocked). */
    public static function statusWord(WeeklyClientStatus $status): string
    {
        return match ($status) {
            WeeklyClientStatus::Blocked => ReportPipeline::BLOCKED,
            WeeklyClientStatus::Risk => ReportPipeline::RISK,
            WeeklyClientStatus::OnTrack => ReportPipeline::ON_TRACK,
        };
    }

    // --- Resumen del cliente (generate-client-summary) -------------------------------------------

    /**
     * formatProjectStatus, con la cartera de ProjectStatusBoard.
     *
     * @param  list<array<string, mixed>>  $projects  filas de ProjectStatusBoard
     */
    public static function clientProjectStatus(array $projects): string
    {
        if ($projects === []) {
            return 'Sin proyectos activos o relevantes.';
        }

        return implode(' ', array_map(function (array $project): string {
            $budget = (int) ($project['budget_minutes'] ?? 0);
            $consumed = (int) ($project['consumed_minutes'] ?? 0);
            $expected = $project['expected_minutes'] ?? null;
            $ratio = $budget > 0 ? $consumed / $budget : 0;

            $state = match (true) {
                $ratio > 1 => 'excedido',
                $ratio >= 0.85 => 'muy consumido',
                default => 'normal',
            };

            $fee = '';

            if ($expected !== null) {
                $percent = $budget > 0 ? round((int) $expected / $budget * 100, 2) : 0;
                $fee = ' esperado '.ReportPipeline::hours((int) $expected).'/'.ReportPipeline::hours($budget).'h ('.self::number((float) $percent).'% del mes)';
            }

            $code = ReportPipeline::normalizeWhitespace($project['code'] ?? '') ?: 'Proyecto';
            $tag = ProjectKindCode::tagLabel(ProjectKindCode::tag((string) ($project['kind_code'] ?? 'GE')));

            return "{$code} ({$tag}): ".ReportPipeline::hours($consumed).'/'.ReportPipeline::hours($budget)."h, estado {$state}.{$fee}";
        }, $projects));
    }

    /** formatStructuredUpdate. */
    public static function structuredUpdate(?WeeklyClientUpdate $update): string
    {
        if ($update === null) {
            return 'Sin weekly estructurada consolidada para esta semana.';
        }

        $sections = [
            'Estado consolidado: '.self::statusWord($update->status).'.',
            'Resumen ejecutivo: '.(self::truncateEllipsis($update->executiveSummary, self::CLIENT_STRUCTURED_SUMMARY_MAX_LENGTH) ?: 'Sin resumen consolidado.'),
        ];

        if ($update->satisfactionScore !== null) {
            $sections[] = 'Snapshot de satisfacción semanal: '.max(0, min(100, $update->satisfactionScore)).'%.';
        }

        $steps = array_values(array_filter(array_map(fn (string $step): string => self::truncateEllipsis($step, 140), $update->nextSteps)));

        if ($steps !== []) {
            $sections[] = 'Próximos pasos: '.implode('; ', $steps).'.';
        }

        $milestones = array_values(array_filter(array_map(
            fn ($milestone): string => trim(ReportPipeline::normalizeWhitespace($milestone->date).' '.ReportPipeline::normalizeWhitespace($milestone->label)),
            $update->milestones,
        )));

        if ($milestones !== []) {
            $sections[] = 'Hitos: '.implode('; ', $milestones).'.';
        }

        $tags = array_values(array_filter(array_map(ReportPipeline::normalizeWhitespace(...), $update->tags)));

        if ($tags !== []) {
            $sections[] = 'Etiquetas: '.implode(', ', $tags).'.';
        }

        return implode(' ', $sections);
    }

    /**
     * formatRawEntries.
     *
     * @param  list<array{authorName: string, submittedAt: string, text: string}>  $entries
     */
    public static function rawEntries(array $entries): string
    {
        if ($entries === []) {
            return 'Sin reportes individuales conservados para esta semana.';
        }

        return implode("\n", array_map(fn (array $entry): string => "- {$entry['authorName']}: {$entry['text']}", $entries));
    }

    /**
     * formatSatisfactionTrend.
     *
     * @param  list<ClientSummaryWeek>  $weeks
     */
    public static function satisfactionTrend(array $weeks): string
    {
        $snapshots = [];

        foreach ($weeks as $week) {
            if ($week->update?->satisfactionScore !== null) {
                $snapshots[] = $week->weekLabel.': '.max(0, min(100, $week->update->satisfactionScore)).'%';
            }
        }

        if (count($snapshots) < 2) {
            return 'Sin suficiente histórico de satisfacción semanal para inferir una tendencia sólida.';
        }

        return implode(' | ', array_reverse($snapshots));
    }

    /**
     * buildClientSummaryPrompt.
     *
     * @param  list<string>  $collaboratorNames
     * @param  list<ClientSummaryWeek>  $weeks
     * @param  list<array<string, mixed>>  $projects
     */
    public static function clientSummary(string $clientName, int $satisfaction, ?string $ownerName, array $collaboratorNames, array $weeks, array $projects): string
    {
        $ownerLine = $ownerName !== null && $ownerName !== '' ? "Responsable actual: {$ownerName}." : 'Responsable actual: sin asignar.';
        $collaboratorsLine = $collaboratorNames !== []
            ? 'Equipo actual: '.implode(', ', $collaboratorNames).'.'
            : 'Equipo actual: sin colaboradores asignados.';

        $context = [];

        foreach ($weeks as $index => $week) {
            $number = $index + 1;
            $date = $week->weekEndDate !== '' ? $week->weekEndDate : ($week->submittedAt !== '' ? $week->submittedAt : 'sin fecha');
            $context[] = trim(implode("\n", [
                "SEMANA {$number}: {$week->weekLabel} ({$date})",
                'Resumen consolidado:',
                self::structuredUpdate($week->update),
                '',
                'Reportes individuales:',
                self::rawEntries($week->rawEntries),
            ]));
        }

        $count = count($weeks);
        $score = max(0, min(100, $satisfaction));
        $projectsText = self::clientProjectStatus($projects);
        $trend = self::satisfactionTrend($weeks);
        $contextText = implode("\n\n---\n\n", $context);

        return trim(<<<PROMPT
Eres un director de cuentas senior. Debes generar un informe de cliente profesional a partir de weeklys reales y reportes individuales.

CLIENTE:
{$clientName}

DATOS ACTUALES DEL CLIENTE:
- Satisfacción actual: {$score}%
- {$ownerLine}
- {$collaboratorsLine}
- Estado de proyectos: {$projectsText}
- Tendencia reciente de satisfacción: {$trend}

HISTÓRICO RELEVANTE (últimas {$count} weeklys con actividad):
{$contextText}

INSTRUCCIONES:
1. Devuelve SOLO markdown estructurado, no texto corrido sin secciones.
2. Usa exactamente estos encabezados ## y en este orden:
   - ## Estado actual
   - ## Satisfacción y tendencia
   - ## Trabajo reciente
   - ## Equipo implicado
   - ## Riesgos y siguientes focos
   - ## Estado de proyectos
3. Dentro de cada sección usa frases cortas y, cuando ayude a escanear, listas con guiones.
3.1. Cada sección debe tener como mucho un párrafo corto inicial y, después, viñetas breves si aportan claridad.
4. Debes analizar explícitamente:
   - estado actual del cliente
   - tendencia de satisfacción y lectura cualitativa de esa tendencia
   - en qué se ha trabajado recientemente
   - qué sigue en curso o pendiente
   - quién lidera o está participando
   - estado del portfolio actual, cargas activas y alertas relevantes
   - riesgos, bloqueos o dependencias si existen
5. No listes los hechos en orden cronológico puro: sintetiza patrones, evolución y situación actual.
6. No repitas información duplicada entre weeklys o personas.
7. Si hay señales mixtas, refléjalas con matiz.
8. Si la tendencia de satisfacción no tiene suficiente base, dilo explícitamente sin inventar.
9. No inventes hechos, hitos, personas, riesgos ni estados.
10. Cita nombres concretos de cliente, personas, hitos o proyectos cuando estén en los datos.
11. Mantén un tono profesional y cercano.
12. No añadas introducciones meta, conclusiones genéricas ni encabezados adicionales distintos a los indicados.
13. Evita bloques largos de texto corrido; prioriza formato escaneable.

Devuelve SOLO el informe final en markdown.
PROMPT);
    }
    // --- Actividad del equipo en un cliente (analyze-team-activity) ------------------------------

    /**
     * El prompt de analyze-team-activity.
     *
     * @param  list<array{memberId: string, memberName: string, reports: list<array{week: string, text: string}>}>  $members
     */
    public static function teamActivity(string $clientName, array $members): string
    {
        $input = self::json($members);
        $noActivity = self::NO_ACTIVITY;

        return "\n".<<<PROMPT
Genera un resumen corto de actividad por miembro para el cliente "{$clientName}".

INPUT_JSON:
{$input}

INSTRUCCIONES:
- Devuelve SIEMPRE JSON válido con esta forma exacta:
{
  "summaries": [
    { "memberId": "...", "summary": "..." }
  ]
}
- summary debe tener 1-2 frases cortas, directas y profesionales.
- Usa solo datos del input, no inventes.
- Si no hay reportes para un miembro, usa: "{$noActivity}"
- Incluye todos los memberId del input exactamente una vez.
- Sin markdown, sin texto extra.

PROMPT;
    }

    // --- Desempeño de una persona (generate-performance-summary) ---------------------------------

    /**
     * El historial de reportes del prompt: una semana por bloque, de la más reciente a la más antigua.
     *
     * @param  list<array{weekLabel: string, submittedDate: string, general: string|null, entries: list<array{clientName: string, text: string}>}>  $submissions
     */
    public static function performanceHistory(array $submissions): string
    {
        return implode("\n---\n\n", array_map(function (array $submission): string {
            $text = "**{$submission['weekLabel']}** ({$submission['submittedDate']})\n";
            $general = $submission['general'] ?? null;
            $text .= 'Reporte general: '.self::truncateRaw($general !== null && $general !== '' ? $general : 'Sin texto general', 420)."\n";

            if ($submission['entries'] !== []) {
                $text .= "Reportes por cliente:\n";

                foreach ($submission['entries'] as $entry) {
                    $text .= "  - {$entry['clientName']}: ".self::truncateRaw($entry['text'], 220)."\n";
                }
            }

            return $text;
        }, $submissions));
    }

    /**
     * El prompt de generate-performance-summary.
     *
     * @param  list<array{weekLabel: string, submittedDate: string, general: string|null, entries: list<array{clientName: string, text: string}>}>  $submissions
     */
    public static function performance(string $userName, array $submissions): string
    {
        $history = self::performanceHistory($submissions);

        return "\n".<<<PROMPT
Genera un resumen profesional de desempeño para {$userName} basándote en sus reportes semanales recientes.

HISTORIAL DE REPORTES (últimas 10 semanas, ordenadas de más reciente a más antigua):
{$history}

INSTRUCCIONES:
1. Analiza las últimas semanas de trabajo
2. Identifica patrones y áreas de enfoque principales
3. Destaca logros y contribuciones clave
4. Menciona clientes en los que trabaja frecuentemente
5. Evalúa consistencia y proactividad
6. Proporciona observaciones constructivas si es relevante

FORMATO DEL RESUMEN:
- Devuelve SOLO markdown estructurado, no párrafos corridos sin secciones.
- Usa exactamente estos encabezados ## y en este orden:
  - ## Enfoque actual
  - ## Clientes y trabajo destacado
  - ## Fortalezas observadas
  - ## Riesgos o puntos a vigilar
- Dentro de cada sección usa frases cortas y listas cuando ayuden a escanear mejor.
- Cada sección debe tener como mucho un párrafo corto inicial y, después, viñetas breves si aportan claridad.
- No añadas encabezados extra, introducciones meta ni cierres genéricos.

IMPORTANTE:
- Sé específico y basado en datos reales de los reportes
- Menciona nombres de clientes y proyectos específicos
- Destaca tendencias y evolución a lo largo del tiempo
- NO inventes información que no esté en los reportes
- Mantén un tono positivo pero objetivo
- Evita bloques largos de texto corrido; prioriza formato escaneable

PROMPT;
    }

    // --- Actividad de una persona por cliente (analyze-user-client-activity) ---------------------

    /**
     * buildUserClientActivityPrompt.
     *
     * @param  list<array{clientId: string, clientName: string, role: string, reports: list<array{weekLabel: string, submittedAt: string, text: string}>}>  $clients
     */
    public static function personClientActivity(string $userName, array $clients): string
    {
        $input = self::json($clients);
        $fallback = self::NO_ACTIVITY;

        return trim(<<<PROMPT
Genera un resumen corto y concreto por cliente para la persona "{$userName}".

INPUT_JSON:
{$input}

INSTRUCCIONES:
- Devuelve SIEMPRE JSON válido con esta forma exacta:
{
  "summaries": [
    { "clientId": "...", "summary": "..." }
  ]
}
- summary debe tener 1-2 frases cortas, directas y profesionales.
- Usa solo datos del input, no inventes.
- Si no hay reportes recientes para un cliente, usa exactamente: "{$fallback}"
- Incluye todos los clientId del input exactamente una vez.
- Resume qué hace esta persona concretamente en cada cliente y en qué está trabajando ahora mismo según sus reportes.
- No describas la actividad general del cliente si no está claramente vinculada al trabajo de esta persona.
- Si la persona lidera el cliente, deja claro su foco o responsabilidad principal.
- Si colabora, explica en qué entregables, tareas o áreas está participando.
- Sin markdown, sin texto extra.
PROMPT);
    }

    // --- Respuestas con una frase por id (equipo y actividad por cliente) ------------------------

    /**
     * normalizeClientActivitySummaries y el bucle final de analyze-team-activity: una frase por id del
     * input; si falta, «Actividad detectada…» si tenía reportes o «Sin actividad…» si no.
     *
     * @param  array<string, bool>  $hasReports  id → ¿tenía reportes?
     * @return array<string, string>
     */
    public static function summariesById(mixed $parsed, string $idKey, array $hasReports): array
    {
        $summaries = [];

        if (is_array($parsed) && is_array($parsed['summaries'] ?? null)) {
            foreach ($parsed['summaries'] as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $id = is_scalar($item[$idKey] ?? null) ? (string) $item[$idKey] : '';
                $summary = is_string($item['summary'] ?? null) ? trim($item['summary']) : '';

                if ($id !== '' && isset($hasReports[$id])) {
                    $summaries[$id] = $summary !== '' ? $summary : self::NO_ACTIVITY;
                }
            }
        }

        foreach ($hasReports as $id => $has) {
            $summaries[(string) $id] ??= $has ? self::ACTIVITY_NOT_SUMMARIZED : self::NO_ACTIVITY;
        }

        return $summaries;
    }

    /**
     * Esquema JSON de las respuestas con una frase por id.
     *
     * @return array<string, mixed>
     */
    public static function summariesSchema(string $idKey): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'summaries' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            $idKey => ['type' => 'STRING'],
                            'summary' => ['type' => 'STRING'],
                        ],
                        'required' => [$idKey, 'summary'],
                    ],
                ],
            ],
            'required' => ['summaries'],
        ];
    }

    private static function number(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return $text === '-0' ? '0' : $text;
    }
}
