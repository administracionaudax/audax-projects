<?php

namespace App\Domain\Weeklies\Report;

use Carbon\CarbonImmutable;
use Collator;

/**
 * Port de `ws:supabase/functions/generate-weekly-report/pipeline.js` y de las reglas de idioma de su
 * index.ts (F-072 a F-076, D-153 y D-188). Lógica pura, sin base de datos ni IA: la usa
 * LlmWeeklyReportGenerator.
 *
 * - Los prompts son los del original, palabra por palabra; solo cambia el «CONTEXTO DE ESTADO DE
 *   PROYECTOS», que sale de los datos reales de Audax (WeeklyProjectStatus) y añade las horas de la
 *   semana de cada proyecto.
 * - Un cliente sin apuntes recibe «Sin novedades» y su estado por el consumo de sus proyectos: más
 *   del 100 % Blocked, desde el 85 % Risk; en un fee mensual, consumir más de lo esperado lo sube a
 *   Risk, o a Blocked con 4 h o más de diferencia (si no ha pasado ya del 100 %).
 * - Los apuntes de un cliente se reparten en lotes de 9.000 caracteres (contados como en JavaScript).
 * - Un «update» de cliente, mientras se construye, es el objeto del original:
 *   {clientId, clientName, executiveSummary, status ("On Track", "Risk" o "Blocked"), nextSteps,
 *   milestones, tags}.
 *
 * @phpstan-type Milestone array{date: string, label: string}
 * @phpstan-type Update array{clientId: int|null, clientName: string, executiveSummary: string, status: string, nextSteps: list<string>, milestones: list<Milestone>, tags: list<string>}
 */
final class ReportPipeline
{
    public const string NO_REPORT_SUMMARY = 'Sin novedades reportadas esta semana.';

    public const int BATCH_CHAR_BUDGET = 9_000;

    /** En WeeklySync, 3 clientes a la vez; aquí van de uno en uno en la cola `ai` (D-146 y D-188). */
    public const int CLIENT_CONCURRENCY = 1;

    public const int GLOBAL_CONTEXT_SUMMARY_CHAR_LIMIT = 900;

    public const float BLOCKED_THRESHOLD = 1.0;

    public const float RISK_THRESHOLD = 0.85;

    /** 4 h sobre lo esperado en un fee: Blocked. */
    public const int FEE_BLOCKED_DELTA_MINUTES = 240;

    public const string ON_TRACK = 'On Track';

    public const string RISK = 'Risk';

    public const string BLOCKED = 'Blocked';

    private const string WHITESPACE = '[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';

    private const string ENGLISH_SIGNAL = '/\b(the|and|with|for|from|this|that|week|weekly|client|project|projects|risk|blocked|on track|next step|next steps|delivered|delivery|review|reviews|feedback|meeting|meetings|design|development|website|campaign|campaigns|pending|done|working|issue|issues)\b/i';

    private const string SPANISH_SIGNAL = '/\b(el|la|los|las|de|del|con|para|esta|semana|cliente|clientes|proyecto|proyectos|riesgo|bloqueado|en curso|en riesgo|próximo|próximos|entrega|entregas|revisión|revisiones|feedback|reunión|reuniones|diseño|desarrollo|campaña|campañas|pendiente|hecho)\b/i';

    // --- Texto ------------------------------------------------------------------------------------

    public static function normalizeWhitespace(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : '';

        return trim((string) preg_replace('/'.self::WHITESPACE.'+/u', ' ', $text), " \t\n\r\0\x0B");
    }

    /** Longitud como `String.length` de JavaScript (unidades UTF-16). */
    public static function jsLength(string $value): int
    {
        return intdiv(strlen((string) mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')), 2);
    }

    public static function formatStatusForPrompt(string $status): string
    {
        return match ($status) {
            self::BLOCKED => 'Bloqueado',
            self::RISK => 'En riesgo',
            default => 'En curso',
        };
    }

    public static function normalizeStatus(mixed $value, string $fallback = self::ON_TRACK): string
    {
        $normalized = mb_strtolower(trim(is_scalar($value) ? (string) $value : ''));

        return match ($normalized) {
            'blocked' => self::BLOCKED,
            'risk' => self::RISK,
            'on track', 'on-track', 'ontrack' => self::ON_TRACK,
            default => $fallback,
        };
    }

    /**
     * @return list<string>
     */
    public static function uniqueStrings(mixed $values): array
    {
        $seen = [];
        $result = [];

        foreach (is_array($values) ? $values : [] as $value) {
            $normalized = self::normalizeWhitespace($value);

            if ($normalized === '' || isset($seen[$key = mb_strtolower($normalized)])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $normalized;
        }

        return $result;
    }

    /**
     * @return list<Milestone>
     */
    public static function normalizeMilestones(mixed $values): array
    {
        $seen = [];
        $result = [];

        foreach (is_array($values) ? $values : [] as $value) {
            $date = self::normalizeWhitespace(is_array($value) ? ($value['date'] ?? '') : '');
            $label = self::normalizeWhitespace(is_array($value) ? ($value['label'] ?? '') : '');

            if ($date === '' || $label === '' || isset($seen[$key = mb_strtolower($date).'|'.mb_strtolower($label)])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = ['date' => $date, 'label' => $label];
        }

        return $result;
    }

    // --- Estado por consumo (F-075) ---------------------------------------------------------------

    /**
     * El proyecto que más pesa en el estado del cliente (getPrimaryProjectRiskState): el de mayor
     * gravedad y, a igual gravedad, el más consumido.
     *
     * @param  list<WeeklyProjectSnapshot>  $projects
     * @return array{project: WeeklyProjectSnapshot, ratio: float, severity: int}|null
     */
    public static function primaryProjectRisk(array $projects): ?array
    {
        $selected = null;

        foreach ($projects as $project) {
            $budget = (int) ($project->budgetMinutes ?? 0);

            if ($budget <= 0) {
                continue;
            }

            $ratio = $project->consumedMinutes / $budget;
            $severity = $ratio > self::BLOCKED_THRESHOLD ? 2 : ($ratio >= self::RISK_THRESHOLD ? 1 : 0);

            if ($project->billingType === WeeklyProjectStatus::KIND_MONTHLY_FEE && (int) $project->expectedMinutes > 0) {
                $delta = $project->consumedMinutes - (int) $project->expectedMinutes;

                if ($delta > 0 && $ratio < self::BLOCKED_THRESHOLD) {
                    $severity = max($severity, $delta >= self::FEE_BLOCKED_DELTA_MINUTES ? 2 : 1);
                }
            }

            if ($selected === null || $severity > $selected['severity'] || ($severity === $selected['severity'] && $ratio > $selected['ratio'])) {
                $selected = ['project' => $project, 'ratio' => $ratio, 'severity' => $severity];
            }
        }

        return $selected;
    }

    /** Etiqueta del tipo de proyecto en los prompts (summaryTag de WeeklySync). */
    public static function projectTag(string $kind): string
    {
        return match ($kind) {
            WeeklyProjectStatus::KIND_HOUR_BANK => 'Bolsa de horas',
            WeeklyProjectStatus::KIND_MONTHLY_FEE => 'Fee mensual',
            WeeklyProjectStatus::KIND_FIXED_PRICE => 'Precio cerrado',
            default => 'Por horas',
        };
    }

    /**
     * @param  list<WeeklyProjectSnapshot>  $projects
     */
    public static function noReportProjectNote(array $projects): string
    {
        $primary = self::primaryProjectRisk($projects);

        if ($primary === null || $primary['severity'] === 0) {
            return '';
        }

        $code = self::normalizeWhitespace($primary['project']->code) ?: 'Proyecto';
        $tag = self::projectTag($primary['project']->billingType);

        return $primary['severity'] === 2
            ? "Aun sin actividad reportada, el proyecto {$code} ({$tag}) está excedido o claramente por encima de lo esperado y requiere seguimiento inmediato."
            : "Aun sin actividad reportada, el proyecto {$code} ({$tag}) muestra señales de riesgo y conviene vigilarlo.";
    }

    /**
     * @return Update
     */
    public static function noReportClientUpdate(ReportClientInput $client): array
    {
        $note = self::noReportProjectNote($client->projects);
        $primary = self::primaryProjectRisk($client->projects);

        return [
            'clientId' => $client->clientId,
            'clientName' => $client->clientName,
            'executiveSummary' => $note !== '' ? self::NO_REPORT_SUMMARY.' '.$note : self::NO_REPORT_SUMMARY,
            'status' => match ($primary['severity'] ?? 0) {
                2 => self::BLOCKED,
                1 => self::RISK,
                default => self::ON_TRACK,
            },
            'nextSteps' => [],
            'milestones' => [],
            'tags' => [],
        ];
    }

    /**
     * @return list<string>
     */
    private static function fallbackSentences(string $text): array
    {
        $normalized = self::normalizeWhitespace($text);

        if ($normalized === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(self::normalizeWhitespace(...), preg_split('/(?<=[.!?])'.self::WHITESPACE.'+/u', $normalized) ?: []),
            fn (string $sentence): bool => $sentence !== '',
        ));
    }

    /**
     * Resumen determinista con los apuntes, si la IA falla con un cliente (buildFallbackClientUpdateFromEntries).
     *
     * @return Update
     */
    public static function fallbackClientUpdateFromEntries(ReportClientInput $client): array
    {
        $noReport = self::noReportClientUpdate($client);

        if (! $client->hasEntries()) {
            return $noReport;
        }

        $snippets = self::uniqueStrings(array_merge(...array_map(
            fn (ReportEntryInput $entry): array => self::fallbackSentences($entry->text),
            $client->entries,
        )));

        $summary = $snippets === []
            ? "Se ha reportado actividad en {$client->clientName} durante la semana, pero la consolidación automática no ha podido resumirla con detalle."
            : implode("\n", ["Se ha reportado actividad en {$client->clientName} durante la semana.", ...array_map(fn (string $snippet): string => "- {$snippet}", $snippets)]);

        return [...$noReport, 'executiveSummary' => $summary];
    }

    // --- Contexto de proyectos ---------------------------------------------------------------------

    /**
     * buildProjectStatusContext, con los datos de Audax: por proyecto el consumo frente al
     * presupuesto, su estado, lo esperado en un fee y las horas de la semana.
     *
     * @param  list<WeeklyProjectSnapshot>  $projects
     */
    public static function projectStatusContext(array $projects): string
    {
        if ($projects === []) {
            return 'Sin alertas relevantes de estado de proyectos esta semana.';
        }

        return implode(' ', array_map(function (WeeklyProjectSnapshot $project): string {
            $label = (self::normalizeWhitespace($project->code) ?: 'Proyecto').' ('.self::projectTag($project->billingType).')';
            $week = $project->weekMinutes > 0 ? '; esta semana '.self::hours($project->weekMinutes).'h' : '';
            $budget = (int) ($project->budgetMinutes ?? 0);

            if ($budget <= 0) {
                return "{$label}: sin presupuesto de horas{$week}.";
            }

            $ratio = $project->consumedMinutes / $budget;
            $status = $ratio > self::BLOCKED_THRESHOLD ? 'EXCEDIDA' : ($ratio >= self::RISK_THRESHOLD ? 'EN RIESGO' : 'NORMAL');
            $expected = (int) ($project->expectedMinutes ?? 0);
            $fee = $expected > 0
                ? '; esperado '.self::hours($expected).'/'.self::hours($budget).'h ('.self::number(round($expected / $budget * 100, 2)).'% del mes)'
                : '';

            return "{$label}: ".self::hours($project->consumedMinutes).'/'.self::hours($budget)."h, estado {$status}{$fee}{$week}.";
        }, $projects));
    }

    /** Minutos en horas como un Number de JavaScript: 750 → «12.5», 600 → «10». */
    public static function hours(int $minutes): string
    {
        return self::number(round($minutes / 60, 2));
    }

    private static function number(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return $text === '-0' ? '0' : $text;
    }

    // --- Lotes ---------------------------------------------------------------------------------------

    public static function formatEntryBlock(ReportEntryInput $entry): string
    {
        $header = ["Entrada {$entry->sequence}", "Autor: {$entry->authorName}"];

        if ($entry->submittedAt !== null && $entry->submittedAt !== '') {
            $header[] = "Enviado: {$entry->submittedAt}";
        }

        return implode(' | ', $header)."\n{$entry->text}";
    }

    /**
     * splitClientEntriesIntoBatches: lotes de $maxChars como mucho (un apunte más largo va solo).
     *
     * @param  list<ReportEntryInput>  $entries
     * @return list<list<ReportEntryInput>>
     */
    public static function splitIntoBatches(array $entries, int $maxChars = self::BATCH_CHAR_BUDGET): array
    {
        $batches = [];
        $current = [];
        $length = 0;

        foreach ($entries as $entry) {
            $block = self::jsLength(self::formatEntryBlock($entry));
            $next = $length === 0 ? $block : $length + 2 + $block;

            if ($current !== [] && $next > $maxChars) {
                $batches[] = $current;
                $current = [$entry];
                $length = $block;

                continue;
            }

            $current[] = $entry;
            $length = $next;
        }

        if ($current !== []) {
            $batches[] = $current;
        }

        return $batches;
    }

    public static function truncateForContext(mixed $value, int $limit): string
    {
        $normalized = self::normalizeWhitespace($value);

        if (mb_strlen($normalized) <= $limit) {
            return $normalized;
        }

        return trim(mb_substr($normalized, 0, max($limit - 1, 0))).'…';
    }

    // --- Prompts (los del original) -------------------------------------------------------------------

    /**
     * @param  list<ReportEntryInput>  $batch
     */
    public static function clientBatchPrompt(ReportClientInput $client, array $batch, int $batchIndex, int $batchCount): string
    {
        $batchLabel = $batchCount > 1
            ? 'Este es el lote '.($batchIndex + 1)." de {$batchCount} para este cliente."
            : 'Este bloque contiene todo el material disponible para este cliente.';
        $entries = implode("\n\n---\n\n", array_map(self::formatEntryBlock(...), $batch));

        return trim(<<<PROMPT
Eres un director de proyectos senior. Debes redactar el update semanal de UN SOLO cliente a partir de reportes internos reales.

CLIENTE:
{$client->clientName}

CONTEXTO DE ESTADO DE PROYECTOS:
{$client->financialContext()}

{$batchLabel}

OBJETIVO:
Generar un resumen ejecutivo rico en detalle para este cliente sin perder ningún hecho importante reportado.

REGLAS:
1. Devuelve SOLO JSON válido con este formato exacto:
{
  "clientName": "{$client->clientName}",
  "executiveSummary": "texto",
  "status": "On Track" | "Risk" | "Blocked",
  "nextSteps": ["Acción 1"],
  "milestones": [{"date": "DD/MM", "label": "Entrega X"}],
  "tags": ["Etiqueta 1"]
}
2. "executiveSummary" no tiene límite fijo de frases o líneas. Debe recoger TODO el detalle relevante que aparezca en los reportes, aunque el texto final sea largo.
2.b. TODOS los textos redactados por ti deben estar en español de España. Si algún reporte trae frases en inglés, tradúcelas al español natural antes de resumirlas.
3. Incluye avances, entregas, decisiones, bloqueos, dependencias, feedback del cliente, revisiones, validaciones y matices relevantes.
4. No escondas hechos importantes detrás de frases vagas. Si varias personas aportan detalles distintos, intégralos todos.
5. Deduplica repeticiones obvias, pero NO elimines información distinta aunque trate el mismo tema.
6. "status" debe reflejar fielmente si el cliente está On Track, en Risk o Blocked según el contenido y el contexto de estado de proyectos.
7. "nextSteps" solo con acciones explícitas o inferencias directísimas del texto.
8. "milestones" solo si el texto menciona entregas, hitos o fechas claras; si no, [].
9. "tags" solo con conceptos claramente sustentados por el texto.
10. No inventes hechos, tareas, fechas, riesgos ni feedback.

REPORTES INTERNOS DEL CLIENTE:
{$entries}
PROMPT);
    }

    /**
     * @param  list<Update>  $partialUpdates
     */
    public static function clientMergePrompt(ReportClientInput $client, array $partialUpdates): string
    {
        $partials = self::jsonPretty($partialUpdates);

        return trim(<<<PROMPT
Eres un director de proyectos senior. Debes fusionar varios resúmenes parciales del mismo cliente en un único update final de weekly.

CLIENTE:
{$client->clientName}

CONTEXTO DE ESTADO DE PROYECTOS:
{$client->financialContext()}

RESÚMENES PARCIALES JSON:
{$partials}

OBJETIVO:
Redactar un único resumen final que conserve TODO el detalle relevante de los parciales sin repetir información.

REGLAS:
1. Devuelve SOLO JSON válido con este formato exacto:
{
  "clientName": "{$client->clientName}",
  "executiveSummary": "texto",
  "status": "On Track" | "Risk" | "Blocked",
  "nextSteps": ["Acción 1"],
  "milestones": [{"date": "DD/MM", "label": "Entrega X"}],
  "tags": ["Etiqueta 1"]
}
2. "executiveSummary" no tiene límite fijo de frases o líneas. Debe conservar TODO el detalle relevante de los parciales, aunque el texto final sea largo.
2.b. TODOS los textos redactados por ti deben estar en español de España. Si los parciales incluyen frases en inglés, tradúcelas al español natural antes de fusionarlas.
3. Conserva avances, entregas, decisiones, bloqueos, dependencias, feedback y matices relevantes de TODOS los parciales.
4. Elimina repeticiones, pero no borres hechos distintos solo porque sean parecidos.
5. Si hay señales mixtas, refléjalas en el resumen final.
6. El status final debe ser "Blocked" si hay un bloqueo activo claro, "Risk" si hay riesgos o dependencias relevantes, y "On Track" solo si no hay ninguna señal de riesgo o bloqueo.
7. Une "nextSteps", "milestones" y "tags" sin duplicados.
8. No inventes hechos nuevos.
PROMPT);
    }

    /**
     * @param  list<Update>  $clientUpdates
     */
    public static function globalSummaryPrompt(array $clientUpdates): string
    {
        $digests = implode("\n\n---\n\n", array_map(function (array $update): string {
            $sections = [
                "Cliente: {$update['clientName']}",
                'Estado: '.self::formatStatusForPrompt($update['status']),
                'Resumen: '.self::truncateForContext($update['executiveSummary'], self::GLOBAL_CONTEXT_SUMMARY_CHAR_LIMIT),
            ];

            if ($update['nextSteps'] !== []) {
                $sections[] = 'Próximos pasos: '.implode('; ', $update['nextSteps']);
            }

            if ($update['milestones'] !== []) {
                $sections[] = 'Hitos: '.implode('; ', array_map(fn (array $item): string => "{$item['date']} {$item['label']}", $update['milestones']));
            }

            return implode("\n", $sections);
        }, $clientUpdates));
        $digests = $digests !== '' ? $digests : 'No hay clientes con actividad consolidada.';

        return trim(<<<PROMPT
Eres un director de proyectos senior. Debes redactar el resumen global de la weekly y los riesgos de equipo a partir de updates ya consolidados por cliente.

CLIENTES CONSOLIDADOS:
{$digests}

OBJETIVO:
1. "globalSummary": un párrafo compacto pero informativo de 4 a 6 frases sobre cómo ha ido la semana.
2. "teamRisks": una lista corta de riesgos globales reales y no duplicados. Incluye solo riesgos que merezcan atención de equipo.

REGLAS:
1. Devuelve SOLO JSON válido con este formato exacto:
{
  "globalSummary": "texto",
  "teamRisks": ["riesgo 1"]
}
1.b. TODO el texto natural de salida debe estar en español de España.
2. No inventes riesgos.
3. Si no hay riesgos globales claros, devuelve "teamRisks": [].
4. Usa solo la información dada.
PROMPT);
    }

    /**
     * Prompt de la traducción forzada de un update de cliente (ensureSpanishClientUpdate, F-076).
     *
     * @param  array<array-key, mixed>  $candidate
     */
    public static function translateClientPrompt(array $candidate): string
    {
        $json = self::jsonPretty($candidate);

        return trim(<<<PROMPT
Reescribe el siguiente JSON para que TODOS los textos narrativos estén en español de España.

REGLAS:
1. Mantén exactamente la misma estructura, claves, arrays y valores no textuales.
2. Conserva EXACTAMENTE "clientName" y "status".
3. Traduce al español natural solo los campos textuales redactados: "executiveSummary", "nextSteps", "milestones.label" y "tags".
4. No cambies el significado, no resumas más y no inventes nada.
5. No dejes frases completas en inglés salvo nombres propios, marcas, siglas o términos sin traducción razonable.
6. Devuelve SOLO JSON válido.

JSON:
{$json}
PROMPT);
    }

    /**
     * @param  array<array-key, mixed>  $candidate
     */
    public static function translateGlobalPrompt(array $candidate): string
    {
        $json = self::jsonPretty($candidate);

        return trim(<<<PROMPT
Reescribe el siguiente JSON para que TODO el texto natural esté en español de España.

REGLAS:
1. Mantén exactamente la misma estructura, claves y arrays.
2. Traduce al español natural solo "globalSummary" y "teamRisks".
3. No cambies el significado, no inventes riesgos y no añadas información nueva.
4. No dejes frases completas en inglés salvo nombres propios, marcas, siglas o términos sin traducción razonable.
5. Devuelve SOLO JSON válido.

JSON:
{$json}
PROMPT);
    }

    // --- Normalizar lo que devuelve la IA -------------------------------------------------------------

    /**
     * @param  array<array-key, mixed>|null  $candidate
     * @return Update
     */
    public static function normalizeClientUpdate(?array $candidate, ReportClientInput $client): array
    {
        $noReport = self::noReportClientUpdate($client);
        $summary = self::normalizeWhitespace($candidate['executiveSummary'] ?? '');

        if ($summary === '' && $client->hasEntries()) {
            return self::fallbackClientUpdateFromEntries($client);
        }

        return [
            'clientId' => $client->clientId,
            'clientName' => $client->clientName,
            'executiveSummary' => $summary !== '' ? $summary : $noReport['executiveSummary'],
            'status' => self::normalizeStatus($candidate['status'] ?? null, $noReport['status']),
            'nextSteps' => self::uniqueStrings($candidate['nextSteps'] ?? []),
            'milestones' => self::normalizeMilestones($candidate['milestones'] ?? []),
            'tags' => self::uniqueStrings($candidate['tags'] ?? []),
        ];
    }

    /**
     * @param  list<Update>  $clientUpdates
     */
    public static function fallbackGlobalSummary(array $clientUpdates): string
    {
        if ($clientUpdates === []) {
            return 'No hay clientes activos para generar la weekly de esta semana.';
        }

        $withActivity = count(self::withActivity($clientUpdates));

        if ($withActivity === 0) {
            return 'No se han reportado novedades relevantes en los clientes activos esta semana.';
        }

        $blocked = count(array_filter($clientUpdates, fn (array $update): bool => $update['status'] === self::BLOCKED));
        $risk = count(array_filter($clientUpdates, fn (array $update): bool => $update['status'] === self::RISK));
        $parts = ["Se ha consolidado actividad en {$withActivity} ".($withActivity === 1 ? 'cliente' : 'clientes').' durante la semana.'];

        if ($blocked > 0) {
            $parts[] = "Hay {$blocked} ".($blocked === 1 ? 'cliente bloqueado' : 'clientes bloqueados').' que requieren atención inmediata.';
        } elseif ($risk > 0) {
            $parts[] = "Se mantienen {$risk} ".($risk === 1 ? 'cliente en riesgo' : 'clientes en riesgo').' con seguimiento activo.';
        } else {
            $parts[] = 'La mayoría de los proyectos siguen su curso sin bloqueos graves.';
        }

        return implode(' ', $parts);
    }

    /**
     * @param  array<array-key, mixed>|null  $value
     * @param  list<Update>  $clientUpdates
     * @return array{globalSummary: string, teamRisks: list<string>}
     */
    public static function normalizeGlobalSummary(?array $value, array $clientUpdates): array
    {
        $summary = self::normalizeWhitespace($value['globalSummary'] ?? '');

        return [
            'globalSummary' => $summary !== '' ? $summary : self::fallbackGlobalSummary($clientUpdates),
            'teamRisks' => self::uniqueStrings($value['teamRisks'] ?? []),
        ];
    }

    /**
     * Los clientes con actividad: los que no tienen exactamente «Sin novedades».
     *
     * @param  list<Update>  $clientUpdates
     * @return list<Update>
     */
    public static function withActivity(array $clientUpdates): array
    {
        return array_values(array_filter($clientUpdates, fn (array $update): bool => self::normalizeWhitespace($update['executiveSummary']) !== self::NO_REPORT_SUMMARY));
    }

    /**
     * Orden alfabético del informe (localeCompare): con la colación española; «General / Interno»
     * (sin cliente) va al final.
     *
     * @param  list<Update>  $clientUpdates
     * @return list<Update>
     */
    public static function sortByName(array $clientUpdates): array
    {
        $collator = class_exists(Collator::class) ? new Collator('es_ES') : null;

        usort($clientUpdates, function (array $a, array $b) use ($collator): int {
            if (($a['clientId'] === null) !== ($b['clientId'] === null)) {
                return $a['clientId'] === null ? 1 : -1;
            }

            $result = $collator?->compare($a['clientName'], $b['clientName']);

            return is_int($result) ? $result : strcmp(mb_strtolower($a['clientName']), mb_strtolower($b['clientName']));
        });

        return $clientUpdates;
    }

    // --- Idioma (F-076) -----------------------------------------------------------------------------

    /**
     * ¿Hay que reescribir en español? (needsSpanishRewrite): al menos 3 señales de inglés y más que
     * de español en los textos narrativos.
     *
     * @param  array<array-key, mixed>|null  $payload
     */
    public static function needsSpanishRewrite(?array $payload): bool
    {
        $text = implode(' ', self::naturalLanguageFields($payload ?? []));

        return $text !== '' && self::needsSpanishText($text);
    }

    /** La misma regla sobre un texto suelto (guion del audio). */
    public static function needsSpanishText(string $text): bool
    {
        $english = (int) preg_match_all(self::ENGLISH_SIGNAL, $text);
        $spanish = (int) preg_match_all(self::SPANISH_SIGNAL, $text);

        return $english >= 3 && $english > $spanish;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return list<string>
     */
    private static function naturalLanguageFields(array $payload): array
    {
        $values = [];

        foreach (['executiveSummary', 'globalSummary'] as $key) {
            if (is_string($payload[$key] ?? null)) {
                $values[] = $payload[$key];
            }
        }

        foreach (['nextSteps', 'tags', 'teamRisks'] as $key) {
            if (is_array($payload[$key] ?? null)) {
                $values = [...$values, ...array_values(array_filter($payload[$key], is_string(...)))];
            }
        }

        if (is_array($payload['milestones'] ?? null)) {
            foreach ($payload['milestones'] as $milestone) {
                if (is_array($milestone) && is_string($milestone['label'] ?? null) && $milestone['label'] !== '') {
                    $values[] = $milestone['label'];
                }
            }
        }

        return array_values(array_filter(array_map('trim', $values), fn (string $value): bool => $value !== ''));
    }

    // --- Utilidades ------------------------------------------------------------------------------------

    /** Fecha y hora de envío en ISO de JavaScript (UTC con milisegundos), como en WeeklySync. */
    public static function isoInstant(?\DateTimeInterface $at): ?string
    {
        return $at === null ? null : CarbonImmutable::instance($at)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    /** JSON.stringify(value, null, 2). */
    public static function jsonPretty(mixed $value): string
    {
        $json = (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return (string) preg_replace_callback('/^( +)/m', fn (array $match): string => str_repeat(' ', intdiv(strlen($match[1]), 2)), $json);
    }
}
