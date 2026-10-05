<?php

namespace App\Domain\Weeklies\Satisfaction;

use App\Domain\Weeklies\Ai\LlmClient;
use App\Domain\Weeklies\Ai\LlmException;
use App\Domain\Weeklies\Ai\LlmNotConfigured;
use App\Domain\Weeklies\Ai\LlmRequest;
use App\Domain\Weeklies\Report\ReportSchemas;
use App\Domain\Weeklies\Report\WeeklyClientUpdate;
use App\Domain\Weeklies\Report\WeeklyReport;
use App\Domain\Weeklies\SatisfactionStabilizer;
use App\Enums\AiFeature;
use App\Models\Client;
use App\Models\ClientSatisfactionSnapshot;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklyEntry;
use Illuminate\Support\Facades\DB;

/**
 * Satisfacción de los clientes al cerrar una semana (F-093 y F-094, D-153), port de
 * `ws:close-week-and-update-satisfaction` (runPostCloseProcessing):
 * - por cada cliente con apuntes enviados esa semana, Gemini propone un delta con el texto de la
 *   semana y su historial (los 5 apuntes anteriores), con el mismo prompt,
 * - SatisfactionStabilizer lo amortigua (el port exacto de satisfaction.js) y la nueva puntuación,
 *   acotada a 0-100, va a clients.satisfaction_score,
 * - queda la foto de cada cliente en client_satisfaction_snapshots (para la gráfica, F-132) y en
 *   el informe (client_updates[].satisfaction_score),
 * - si la IA falla con un cliente, ese cliente no cambia y se sigue con los demás.
 * Idempotente: un cliente que ya tiene su foto de esa semana no se vuelve a mover.
 */
final class SatisfactionUpdater
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly SatisfactionStabilizer $stabilizer = new SatisfactionStabilizer,
    ) {}

    /**
     * @return array<int, int> cliente → nueva puntuación (también las que ya estaban de antes)
     */
    public function update(WeeklyCycle $cycle, ?User $user = null): array
    {
        $texts = $this->weekTexts($cycle);
        $done = ClientSatisfactionSnapshot::query()->where('weekly_cycle_id', $cycle->id)->pluck('score', 'client_id')->map(fn ($score): int => (int) $score)->all();
        $scores = $done;

        $clients = Client::query()->whereIn('id', array_keys($texts))->orderBy('name')->get(['id', 'name', 'satisfaction_score']);

        foreach ($clients as $client) {
            if (isset($done[$client->id])) {
                continue;
            }

            try {
                $scores[$client->id] = $this->updateClient($cycle, $client, implode("\n\n", $texts[$client->id]), $user);
            } catch (LlmNotConfigured $e) {
                throw $e;
            } catch (LlmException $e) {
                report($e);
            }
        }

        $this->writeReport($cycle, $scores);

        return $scores;
    }

    /**
     * El prompt del original, palabra por palabra.
     */
    public static function prompt(string $clientName, int $current, string $weekText, string $history): string
    {
        return trim(<<<PROMPT
Eres un analista experto en relaciones con clientes. Debes evaluar el nivel de satisfacción de un cliente basándote en el conjunto consolidado de reportes de una weekly cerrada.

CLIENTE: {$clientName}
SATISFACCIÓN ACTUAL: {$current}% (escala 0-100)

REPORTE CONSOLIDADO DE ESTA SEMANA:
{$weekText}

HISTORIAL RECIENTE (últimas 5 semanas):
{$history}

INSTRUCCIONES:
1. Analiza el conjunto consolidado de esta semana, no opiniones individuales aisladas.
2. Evalúa el sentimiento general y devuelve SOLO JSON.
3. Usa cambios graduales y consistentes con el histórico.
4. Si no hay evidencia explícita de satisfacción o insatisfacción del cliente, usa 0.
5. El trabajo rutinario, los seguimientos normales y avances sin reacción clara del cliente no deben mover el índice.
6. Si hay señales leves pero reales de mejora o empeoramiento, puedes recomendar cambios pequeños de 1 a 3 puntos.
7. Reserva cambios de 6 a 8 puntos solo para casos muy claramente positivos o muy claramente negativos.

RESPUESTA REQUERIDA:
{
  "recommendedDelta": <número entre -8 y +8>,
  "sentiment": "<MUY_POSITIVO|POSITIVO|LIGERAMENTE_POSITIVO|NEUTRAL|LIGERAMENTE_NEGATIVO|NEGATIVO|MUY_NEGATIVO>",
  "evidenceLevel": "<NONE|LOW|MEDIUM|HIGH>",
  "explicitClientImpact": <true|false>,
  "confidence": <número entre 0 y 1>,
  "reasoning": "<breve explicación>"
}
PROMPT);
    }

    private function updateClient(WeeklyCycle $cycle, Client $client, string $weekText, ?User $user): int
    {
        // `client.current_satisfaction || 50`: un 0 también cuenta como 50, como en el original.
        $current = $client->satisfaction_score ?: 50;
        $response = $this->llm->generate(new LlmRequest(
            feature: AiFeature::Satisfaction,
            prompt: self::prompt($client->name, $current, $weekText, $this->history($cycle, $client)),
            responseSchema: ReportSchemas::satisfaction(),
            user: $user,
            subject: $client,
            operation: 'close_week',
            metadata: ['weekly_cycle_id' => $cycle->id, 'client_id' => $client->id],
        ));
        $analysis = $response->json ?? [];

        $input = ['currentSatisfaction' => $current, 'reportText' => $weekText, 'explicitClientImpact' => ($analysis['explicitClientImpact'] ?? null) === true];
        foreach (['recommendedDelta' => 'requestedDelta', 'evidenceLevel' => 'evidenceLevel', 'confidence' => 'confidence'] as $from => $to) {
            if (array_key_exists($from, $analysis)) {
                $input[$to] = $analysis[$from];
            }
        }

        $decision = $this->stabilizer->stabilize($input);
        $score = $decision->applyTo($current);
        $evidence = $analysis['evidenceLevel'] ?? null;
        $confidence = $analysis['confidence'] ?? null;
        $requested = $analysis['recommendedDelta'] ?? null;

        DB::transaction(function () use ($cycle, $client, $current, $score, $decision, $analysis, $evidence, $confidence, $requested, $response): void {
            ClientSatisfactionSnapshot::query()->updateOrCreate(
                ['client_id' => $client->id, 'weekly_cycle_id' => $cycle->id],
                [
                    'score' => $score,
                    'previous_score' => $current,
                    'requested_delta' => is_numeric($requested) ? (int) round((float) $requested) : null,
                    'delta' => $decision->finalDelta,
                    'rule' => $decision->rule,
                    'reasoning' => $this->stabilizer->stableReasoning($analysis['reasoning'] ?? null, $decision->finalDelta, $decision->rule),
                    'evidence_level' => mb_strtoupper(is_scalar($evidence) && (string) $evidence !== '' ? (string) $evidence : 'NONE'),
                    'explicit_client_impact' => ($analysis['explicitClientImpact'] ?? null) === true,
                    'confidence' => is_numeric($confidence) ? (string) max(0, min(1, (float) $confidence)) : null,
                    'metrics' => $decision->metrics->toArray(),
                    'model' => $response->model,
                ],
            );

            $client->forceFill(['satisfaction_score' => $score])->save();
        });

        return $score;
    }

    /**
     * Los textos de cada cliente esa semana, en orden de envío.
     *
     * @return array<int, list<string>>
     */
    private function weekTexts(WeeklyCycle $cycle): array
    {
        $texts = [];

        WeeklyEntry::query()
            ->join('weekly_submissions', 'weekly_submissions.id', '=', 'weekly_entries.weekly_submission_id')
            ->where('weekly_submissions.weekly_cycle_id', $cycle->id)
            ->whereNotNull('weekly_submissions.submitted_at')
            ->whereNotNull('weekly_entries.client_id')
            ->orderBy('weekly_submissions.submitted_at')
            ->orderBy('weekly_submissions.id')
            ->get(['weekly_entries.client_id', 'weekly_entries.body'])
            ->each(function (WeeklyEntry $entry) use (&$texts): void {
                if (trim((string) $entry->body) !== '') {
                    $texts[(int) $entry->client_id][] = (string) $entry->body;
                }
            });

        return $texts;
    }

    /**
     * Los 5 apuntes más recientes del cliente en otras semanas: «[Semana 40 (…)]: texto».
     */
    private function history(WeeklyCycle $cycle, Client $client): string
    {
        $rows = DB::table('weekly_entries')
            ->join('weekly_submissions', 'weekly_submissions.id', '=', 'weekly_entries.weekly_submission_id')
            ->join('weekly_cycles', 'weekly_cycles.id', '=', 'weekly_submissions.weekly_cycle_id')
            ->where('weekly_entries.client_id', $client->id)
            ->where('weekly_submissions.weekly_cycle_id', '!=', $cycle->id)
            ->whereNotNull('weekly_submissions.submitted_at')
            ->orderByDesc('weekly_submissions.submitted_at')
            ->limit(5)
            ->get(['weekly_cycles.label', 'weekly_entries.body']);

        $history = $rows->map(fn (object $row): string => "[{$row->label}]: {$row->body}")->implode("\n");

        return $history !== '' ? $history : 'Sin historial previo';
    }

    /**
     * @param  array<int, int>  $scores
     */
    private function writeReport(WeeklyCycle $cycle, array $scores): void
    {
        $report = $cycle->reportData();

        if ($report === null || $scores === []) {
            return;
        }

        $cycle->forceFill(['report' => (new WeeklyReport(
            $report->globalSummary,
            $report->teamRisks,
            array_map(fn (WeeklyClientUpdate $update): WeeklyClientUpdate => $update->clientId !== null && isset($scores[$update->clientId])
                ? $update->withSatisfaction($scores[$update->clientId])
                : $update, $report->clientUpdates),
        ))->toArray()])->save();
    }
}
