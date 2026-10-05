<?php

namespace App\Domain\Weeklies\Audio;

use App\Domain\Weeklies\Ai\LlmClient;
use App\Domain\Weeklies\Ai\LlmException;
use App\Domain\Weeklies\Ai\LlmNotConfigured;
use App\Domain\Weeklies\Ai\LlmRequest;
use App\Domain\Weeklies\Report\ReportPipeline;
use App\Domain\Weeklies\Report\ReportSchemas;
use App\Domain\Weeklies\Report\WeeklyClientUpdate;
use App\Domain\Weeklies\Report\WeeklyProjectSnapshot;
use App\Domain\Weeklies\Report\WeeklyProjectStatus;
use App\Domain\Weeklies\Report\WeeklyReport;
use App\Enums\AiFeature;
use App\Enums\WeeklyAudioSectionKind;
use App\Enums\WeeklyClientStatus;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklySubmission;

/**
 * Guion del audio por secciones (F-084), port de `ws:generate-audio-tts` (modo `scripts`) y de
 * `ws:App.tsx` handleGenerateReportAudio: entrada, un bloque por cliente del informe (con sus
 * reportes originales y el estado de sus proyectos) y cierre, con los mismos prompts. Gemini
 * devuelve el JSON {intro, clients[{clientKey, script}], outro}; lo que falta o no se puede leer se
 * completa con el texto determinista del original, y si la IA no responde, todo el guion sale así
 * (como su modo `full`). Una sección en inglés se reescribe en español (F-076).
 *
 * Claves (weekly_audio_sections.key): "intro", "client-{id}" (o "client-general") y "outro"; sin
 * clientes, una sola sección "full-weekly".
 *
 * @phpstan-type Section array{key: string, kind: WeeklyAudioSectionKind, client_id: int|null, client_name: string|null, script: string}
 * @phpstan-type ClientSection array{clientId: int|null, clientName: string, status: string, executiveSummary: string, nextSteps: list<string>, milestones: list<array{date: string|null, label: string}>, rawReports: list<array{authorName: string, content: string}>, projectStatusText?: string, clientKey: string}
 */
final class WeeklyAudioScripts
{
    public function __construct(private readonly LlmClient $llm) {}

    /**
     * @return list<Section>
     */
    public function build(WeeklyCycle $cycle, WeeklyReport $report, ?string $reportText, ?User $user = null): array
    {
        $narration = self::narrationText($report, $reportText);
        $clients = $this->clientSections($cycle, $report);

        try {
            return $this->generated($cycle, $report, $narration, $clients, $user);
        } catch (LlmNotConfigured $e) {
            throw $e;
        } catch (LlmException $e) {
            report($e);

            return self::deterministic($report, $narration, $clients);
        }
    }

    /**
     * El texto del informe para narrar (textToNarrate de App.tsx).
     */
    public static function narrationText(WeeklyReport $report, ?string $reportText): string
    {
        if ($report->clientUpdates === [] && $report->globalSummary === '') {
            return (string) $reportText;
        }

        $text = "Resumen Global: {$report->globalSummary}.\n\n";

        foreach ($report->clientUpdates as $update) {
            $text .= "Cliente {$update->clientName} (Estado: ".self::statusLabel($update->status)."): {$update->executiveSummary}.\n";

            if ($update->nextSteps !== []) {
                $text .= 'Próximos pasos: '.implode(', ', $update->nextSteps).".\n";
            }
        }

        if ($report->teamRisks !== []) {
            $text .= "\nRiesgos del equipo: ".implode('. ', $report->teamRisks).".\n";
        }

        return $text;
    }

    /** getNarrationStatusLabel. */
    public static function statusLabel(WeeklyClientStatus $status): string
    {
        return match ($status) {
            WeeklyClientStatus::Blocked => 'Bloqueado',
            WeeklyClientStatus::Risk => 'En riesgo',
            WeeklyClientStatus::OnTrack => 'En curso',
        };
    }

    public static function clientKey(?int $clientId): string
    {
        return $clientId === null ? 'client-general' : "client-{$clientId}";
    }

    /**
     * Las secciones de cliente del original: su update, sus reportes originales enviados y el estado
     * de sus proyectos.
     *
     * @return list<ClientSection>
     */
    public function clientSections(WeeklyCycle $cycle, WeeklyReport $report): array
    {
        $raw = [];
        $submissions = WeeklySubmission::query()
            ->submitted()
            ->where('weekly_cycle_id', $cycle->id)
            ->with(['user:id,name', 'entries:id,weekly_submission_id,client_id,body,position'])
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->get();

        foreach ($submissions as $submission) {
            foreach ($submission->entries as $entry) {
                $body = trim((string) $entry->body);

                if ($body !== '') {
                    $raw[self::clientKey($entry->client_id)][] = ['authorName' => $submission->user->name ?? 'Usuario', 'content' => $body];
                }
            }
        }

        return array_map(function (WeeklyClientUpdate $update) use ($raw): array {
            $section = [
                'clientId' => $update->clientId,
                'clientName' => $update->clientName,
                'status' => self::originalStatus($update->status),
                'executiveSummary' => $update->executiveSummary,
                'nextSteps' => $update->nextSteps,
                'milestones' => array_map(fn ($milestone): array => $milestone->toArray(), $update->milestones),
                'rawReports' => $raw[self::clientKey($update->clientId)] ?? [],
            ];

            $status = self::projectStatusText($update->projects);
            if ($status !== null) {
                $section['projectStatusText'] = $status;
            }

            $section['clientKey'] = self::clientKey($update->clientId);

            return $section;
        }, $report->clientUpdates);
    }

    /**
     * «ARR-BH1 (Bolsa de horas): 12.5h consumidas de 20h; esperado 10h (50%)», como App.tsx.
     *
     * @param  list<WeeklyProjectSnapshot>  $projects
     */
    public static function projectStatusText(array $projects): ?string
    {
        if ($projects === []) {
            return null;
        }

        return implode('. ', array_map(function (WeeklyProjectSnapshot $project): string {
            $label = "{$project->code} (".ReportPipeline::projectTag($project->billingType).')';

            if ($project->budgetMinutes === null || $project->budgetMinutes <= 0) {
                return "{$label}: ".ReportPipeline::hours($project->weekMinutes).'h esta semana';
            }

            $expected = $project->billingType === WeeklyProjectStatus::KIND_MONTHLY_FEE && $project->expectedMinutes !== null
                ? '; esperado '.ReportPipeline::hours($project->expectedMinutes).'h ('.(int) round($project->expectedMinutes / $project->budgetMinutes * 100).'%)'
                : '';

            return "{$label}: ".ReportPipeline::hours($project->consumedMinutes).'h consumidas de '.ReportPipeline::hours($project->budgetMinutes).'h'.$expected;
        }, $projects));
    }

    /**
     * generateSectionScripts: la IA escribe el guion y lo que falta se completa sin IA.
     *
     * @param  list<ClientSection>  $clients
     * @return list<Section>
     */
    private function generated(WeeklyCycle $cycle, WeeklyReport $report, string $narration, array $clients, ?User $user): array
    {
        if ($clients === []) {
            $prompt = trim(<<<PROMPT
Eres una locutora profesional de una agencia digital llamada Audax Studio. Convierte el siguiente informe semanal en una narración natural, completa y en español de España.

INFORME:
{$narration}

REGLAS:
- Mantén todos los hechos relevantes.
- No uses markdown ni bullets.
- Devuelve SOLO el texto final.
PROMPT);

            return [self::section('full-weekly', WeeklyAudioSectionKind::Intro, null, null, $this->text($prompt, $cycle, $user, 'narration_full'))];
        }

        $globalSummary = $report->globalSummary !== '' ? $report->globalSummary : $narration;
        $risks = $report->teamRisks !== [] ? implode(' | ', $report->teamRisks) : 'Sin riesgos destacados';
        $clientsJson = ReportPipeline::jsonPretty($clients);
        $prompt = trim(<<<PROMPT
Eres guionista de locuciones internas para Audax Studio.

Tu trabajo es crear un guion de audio COMPLETO y SIN PÉRDIDA DE INFORMACIÓN para una weekly.

Devuelve SOLO JSON válido con este formato exacto:
{
  "intro": "texto",
  "clients": [
    { "clientKey": "clave-exacta", "script": "texto" }
  ],
  "outro": "texto"
}

REGLAS CRÍTICAS:
1. El idioma debe ser SIEMPRE español de España.
2. El campo "intro" debe ser breve: 2-4 frases.
3. El campo "outro" debe ser breve: 1-3 frases.
4. Para cada cliente, el campo "script" debe incluir TODAS las novedades únicas y relevantes.
5. Si dos personas reportan lo mismo, deduplica la idea, pero NO elimines detalles nuevos.
6. Si un cliente tiene mucha actividad, su bloque puede ser largo. NO impongas un límite de frases.
7. Mantén el orden exacto de clientes que se te proporciona.
8. Cada item del array "clients" debe usar EXACTAMENTE la misma "clientKey" recibida.
9. No añadas clientes extra. No omitas clientes.
10. No uses markdown, bullets ni encabezados.
11. No dejes frases completas en inglés salvo nombres propios, marcas, siglas o términos técnicos sin traducción razonable.
12. Si hay estado de proyectos relevante, intégralo en la narración del cliente sin sonar telegráfico.

CONTEXTO GLOBAL:
Resumen global: {$globalSummary}
Riesgos de equipo: {$risks}

CLIENTES:
{$clientsJson}
PROMPT);

        $response = $this->llm->generate(new LlmRequest(
            feature: AiFeature::AudioScript,
            prompt: $prompt,
            responseSchema: ReportSchemas::narration(),
            user: $user,
            subject: $cycle,
            operation: 'narration',
            metadata: ['weekly_cycle_id' => $cycle->id, 'clients' => count($clients)],
        ));
        $json = $response->json ?? [];

        $scripts = [];
        foreach (is_array($json['clients'] ?? null) ? $json['clients'] : [] as $item) {
            if (is_array($item) && is_string($item['clientKey'] ?? null) && is_string($item['script'] ?? null) && trim($item['script']) !== '') {
                $scripts[$item['clientKey']] = trim($item['script']);
            }
        }

        $intro = is_string($json['intro'] ?? null) && trim($json['intro']) !== '' ? trim($json['intro']) : self::introFallback($report, $narration);
        $outro = is_string($json['outro'] ?? null) && trim($json['outro']) !== '' ? trim($json['outro']) : self::outroFallback($report);
        $sections = [self::section('intro', WeeklyAudioSectionKind::Intro, null, null, $intro)];

        foreach ($clients as $client) {
            $sections[] = self::section($client['clientKey'], WeeklyAudioSectionKind::Client, $client['clientId'], $client['clientName'], $scripts[$client['clientKey']] ?? self::clientFallback($client));
        }

        $sections[] = self::section('outro', WeeklyAudioSectionKind::Outro, null, null, $outro);

        return array_map(fn (array $section): array => ReportPipeline::needsSpanishText($section['script'])
            ? [...$section, 'script' => $this->translate($section['script'], $cycle, $user)]
            : $section, $sections);
    }

    /**
     * buildDeterministicSectionScripts: sin IA.
     *
     * @param  list<ClientSection>  $clients
     * @return list<Section>
     */
    public static function deterministic(WeeklyReport $report, string $narration, array $clients): array
    {
        if ($clients === []) {
            $script = trim($narration) !== '' ? trim($narration) : self::introFallback($report, $narration);

            return [self::section('full-weekly', WeeklyAudioSectionKind::Intro, null, null, $script)];
        }

        $sections = [self::section('intro', WeeklyAudioSectionKind::Intro, null, null, self::introFallback($report, $narration))];

        foreach ($clients as $client) {
            $sections[] = self::section($client['clientKey'], WeeklyAudioSectionKind::Client, $client['clientId'], $client['clientName'], self::clientFallback($client));
        }

        $sections[] = self::section('outro', WeeklyAudioSectionKind::Outro, null, null, self::outroFallback($report));

        return array_values(array_filter($sections, fn (array $section): bool => trim($section['script']) !== ''));
    }

    public static function introFallback(WeeklyReport $report, string $narration): string
    {
        $summary = trim($report->globalSummary) !== '' ? trim($report->globalSummary) : trim($narration);

        return "Hola equipo. Vamos con el resumen semanal. {$summary}";
    }

    public static function outroFallback(WeeklyReport $report): string
    {
        return $report->teamRisks !== []
            ? 'Como puntos de atención del equipo, tenemos: '.implode('. ', $report->teamRisks).'. Y con esto cerramos el repaso de la semana.'
            : 'Y con esto cerramos el repaso de la semana. Gracias a todos.';
    }

    /**
     * buildClientFallback.
     *
     * @param  ClientSection  $client
     */
    public static function clientFallback(array $client): string
    {
        $parts = [trim($client['executiveSummary']) !== ''
            ? "En cuanto a {$client['clientName']}, ".trim($client['executiveSummary'])
            : "En cuanto a {$client['clientName']}, no hay un resumen ejecutivo estructurado, pero sí actividad registrada."];

        $raw = array_values(array_unique(array_filter(array_map(fn (array $report): string => trim($report['content']), $client['rawReports']), fn (string $text): bool => $text !== '')));
        if ($raw !== []) {
            $parts[] = 'En los reportes del equipo se mencionó lo siguiente: '.implode(' ', $raw);
        }

        if ($client['nextSteps'] !== []) {
            $parts[] = 'Próximos pasos: '.implode(', ', $client['nextSteps']).'.';
        }

        $milestones = implode(', ', array_map(
            fn (array $milestone): string => ($milestone['date'] ?? null) ? "{$milestone['date']}: {$milestone['label']}" : $milestone['label'],
            array_values(array_filter($client['milestones'], fn (array $milestone): bool => trim($milestone['label']) !== '')),
        ));
        if ($milestones !== '') {
            $parts[] = "Próximos hitos: {$milestones}.";
        }

        $projects = trim($client['projectStatusText'] ?? '');
        if ($projects !== '') {
            $parts[] = "Estado de proyectos: {$projects}";
        }

        return trim((string) preg_replace('/\s+/u', ' ', implode(' ', $parts)));
    }

    /**
     * @return Section
     */
    private static function section(string $key, WeeklyAudioSectionKind $kind, ?int $clientId, ?string $clientName, string $script): array
    {
        return ['key' => $key, 'kind' => $kind, 'client_id' => $clientId, 'client_name' => $clientName, 'script' => trim($script)];
    }

    private static function originalStatus(WeeklyClientStatus $status): string
    {
        return match ($status) {
            WeeklyClientStatus::Blocked => ReportPipeline::BLOCKED,
            WeeklyClientStatus::Risk => ReportPipeline::RISK,
            WeeklyClientStatus::OnTrack => ReportPipeline::ON_TRACK,
        };
    }

    /** generateNarrationText: texto libre, reescrito en español si viene en inglés. */
    private function text(string $prompt, WeeklyCycle $cycle, ?User $user, string $operation): string
    {
        $text = trim($this->llm->generate(new LlmRequest(
            feature: AiFeature::AudioScript,
            prompt: $prompt,
            user: $user,
            subject: $cycle,
            operation: $operation,
            metadata: ['weekly_cycle_id' => $cycle->id],
        ))->text);

        return ReportPipeline::needsSpanishText($text) ? $this->translate($text, $cycle, $user) : $text;
    }

    private function translate(string $script, WeeklyCycle $cycle, ?User $user): string
    {
        $prompt = trim(<<<PROMPT
Convierte el siguiente guion a español de España natural para locución.

REGLAS:
1. Mantén el contenido factual y el orden.
2. Traduce cualquier frase completa en inglés al español.
3. No dejes frases completas en inglés salvo nombres propios, marcas, siglas o términos técnicos sin traducción razonable.
4. Devuelve SOLO el texto final del guion, sin explicaciones.

GUIÓN:
{$script}
PROMPT);

        $text = trim($this->llm->generate(new LlmRequest(
            feature: AiFeature::AudioScript,
            prompt: $prompt,
            user: $user,
            subject: $cycle,
            operation: 'translate_script',
            metadata: ['weekly_cycle_id' => $cycle->id],
        ))->text);

        return $text !== '' ? $text : $script;
    }
}
