<?php

namespace App\Domain\Weeklies;

use App\Domain\Weeklies\Ai\LlmClient;
use App\Domain\Weeklies\Ai\LlmException;
use App\Domain\Weeklies\Ai\LlmNotConfigured;
use App\Domain\Weeklies\Ai\LlmRequest;
use App\Domain\Weeklies\Ai\LlmUnavailable;
use App\Domain\Weeklies\Report\ReportClientInput;
use App\Domain\Weeklies\Report\ReportEntryInput;
use App\Domain\Weeklies\Report\ReportPipeline;
use App\Domain\Weeklies\Report\ReportSchemas;
use App\Domain\Weeklies\Report\WeeklyClientUpdate;
use App\Domain\Weeklies\Report\WeeklyMilestone;
use App\Domain\Weeklies\Report\WeeklyProjectSnapshot;
use App\Domain\Weeklies\Report\WeeklyProjectStatus;
use App\Domain\Weeklies\Report\WeeklyReport;
use App\Domain\Weeklies\Report\WeeklyReportText;
use App\Enums\AiFeature;
use App\Enums\WeeklyClientStatus;
use App\Models\Client;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklySubmission;
use Closure;

/**
 * El informe de la weekly con Gemini (F-072 a F-076, D-146 y D-188), port de
 * `ws:generate-weekly-report` (index.ts y pipeline.js, en Report\ReportPipeline):
 * 1. los apuntes ENVIADOS de la semana, en orden de envío, agrupados por cliente: los clientes
 *    activos y los que tengan apuntes aunque ya no lo estén, y al final «General / Interno» si alguien
 *    escribió sin cliente (D-189),
 * 2. por cliente con apuntes: lotes de 9.000 caracteres, una llamada por lote y, si hay varios, otra
 *    para fusionarlos; si la IA falla con un cliente, su resumen sale de los apuntes (sin IA),
 * 3. el texto final de cada cliente y el resumen global se reescriben en español si vienen en
 *    inglés (traducción forzada, F-076),
 * 4. a cada cliente se le añade el estado de sus proyectos (WeeklyProjectStatus, con HourBankLedger),
 *    y uno sin apuntes recibe «Sin novedades» con el estado por su consumo,
 * 5. el resumen global y los riesgos del equipo; si nadie escribió, sin IA.
 * Las llamadas van de una en una (en WeeklySync eran 3 clientes a la vez): es la cola `ai` y el Job
 * tiene 10 minutos. Como en el original, si se acerca el límite se para con un error claro.
 */
final class LlmWeeklyReportGenerator implements WeeklyReportGenerator
{
    /** Margen bajo los 600 s del Job (AiQueue::TIMEOUT), como los 10 minutos del original. */
    public const int DEADLINE_SECONDS = 570;

    private float $deadline = 0.0;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly WeeklyProjectStatus $status,
    ) {}

    public function generate(WeeklyCycle $cycle, ?User $requestedBy = null, ?Closure $progress = null): GeneratedWeeklyReport
    {
        $this->deadline = microtime(true) + self::DEADLINE_SECONDS;

        $submissions = WeeklySubmission::query()
            ->submitted()
            ->where('weekly_cycle_id', $cycle->id)
            ->with(['user:id,name', 'entries:id,weekly_submission_id,client_id,body,position'])
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->get();

        $clients = $this->clientInputs($cycle, array_values($submissions->all()));
        $total = count($clients);
        $updates = [];
        $projects = [];

        foreach ($clients as $index => $client) {
            $this->checkDeadline();
            $updates[] = $this->clientUpdate($client, $cycle, $requestedBy);
            $projects[$this->key($client->clientId)] = $client->projects;

            if ($progress !== null) {
                $progress($index + 1, $total, 'clients');
            }
        }

        $updates = ReportPipeline::sortByName($updates);

        if ($progress !== null) {
            $progress($total, $total, 'summary');
        }

        $global = ReportPipeline::withActivity($updates) !== []
            ? $this->globalSummary($updates, $cycle, $requestedBy)
            : ['globalSummary' => ReportPipeline::fallbackGlobalSummary($updates), 'teamRisks' => []];

        $report = new WeeklyReport(
            globalSummary: $global['globalSummary'],
            teamRisks: $global['teamRisks'],
            clientUpdates: array_map(fn (array $update): WeeklyClientUpdate => $this->toClientUpdate(
                $update,
                $projects[$this->key($update['clientId'])] ?? [],
                $clients,
            ), $updates),
        );

        return new GeneratedWeeklyReport($report, WeeklyReportText::markdown($cycle, $report), $submissions->count(), $this->llm->model());
    }

    /**
     * groupClientInputs: un ReportClientInput por cliente, con sus apuntes en orden de envío.
     *
     * @param  list<WeeklySubmission>  $submissions
     * @return list<ReportClientInput>
     */
    public function clientInputs(WeeklyCycle $cycle, array $submissions): array
    {
        $entries = [];
        $withEntries = [];

        foreach ($submissions as $position => $submission) {
            foreach ($submission->entries as $entry) {
                if (trim((string) $entry->body) === '') {
                    continue;
                }

                if ($entry->client_id !== null) {
                    $withEntries[$entry->client_id] = $entry->client_id;
                }

                $entries[$this->key($entry->client_id)][] = new ReportEntryInput(
                    sequence: $position + 1,
                    authorName: ReportPipeline::normalizeWhitespace($submission->user->name ?? '') ?: 'Usuario eliminado',
                    submittedAt: ReportPipeline::isoInstant($submission->submitted_at),
                    text: trim((string) $entry->body),
                );
            }
        }

        $models = Client::query()
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', array_values($withEntries)))
            ->orderBy('name')
            ->get(['id', 'name', 'satisfaction_score']);
        $snapshots = $this->status->forCycle($cycle, array_values(array_map(intval(...), $models->modelKeys())));

        $inputs = array_values($models->map(fn (Client $client): ReportClientInput => new ReportClientInput(
            clientId: $client->id,
            clientName: $client->name,
            currentSatisfaction: $client->satisfaction_score,
            projects: $snapshots[$client->id] ?? [],
            entries: $entries[$this->key($client->id)] ?? [],
        ))->all());

        if (isset($entries['general'])) {
            $inputs[] = new ReportClientInput(null, (string) __('weeklies.report.general'), entries: $entries['general']);
        }

        return $inputs;
    }

    /**
     * Un cliente: lotes, fusión y traducción (buildStructuredWeeklyReport, por cliente).
     *
     * @return array{clientId: int|null, clientName: string, executiveSummary: string, status: string, nextSteps: list<string>, milestones: list<array{date: string, label: string}>, tags: list<string>}
     */
    private function clientUpdate(ReportClientInput $client, WeeklyCycle $cycle, ?User $user): array
    {
        if (! $client->hasEntries()) {
            return ReportPipeline::noReportClientUpdate($client);
        }

        $batches = ReportPipeline::splitIntoBatches($client->entries);
        $count = count($batches);

        try {
            $partials = [];

            foreach ($batches as $index => $batch) {
                $json = $this->ask(ReportPipeline::clientBatchPrompt($client, $batch, $index, $count), ReportSchemas::clientUpdate(), $cycle, $user, 'client_batch', [
                    'client_id' => $client->clientId, 'batch_index' => $index, 'batch_count' => $count,
                ]);
                $partials[] = ReportPipeline::normalizeClientUpdate($json, $client);
            }

            $final = $count === 1
                ? $partials[0]
                : $this->ask(ReportPipeline::clientMergePrompt($client, $partials), ReportSchemas::clientUpdate(), $cycle, $user, 'client_merge', ['client_id' => $client->clientId]);

            if (ReportPipeline::needsSpanishRewrite($final)) {
                $final = $this->ask(ReportPipeline::translateClientPrompt($final), ReportSchemas::clientUpdate(), $cycle, $user, 'translate_client', ['client_id' => $client->clientId]);
            }

            return ReportPipeline::normalizeClientUpdate($final, $client);
        } catch (LlmNotConfigured $e) {
            throw $e;
        } catch (LlmException $e) {
            report($e);

            return ReportPipeline::fallbackClientUpdateFromEntries($client);
        }
    }

    /**
     * @param  list<array{clientId: int|null, clientName: string, executiveSummary: string, status: string, nextSteps: list<string>, milestones: list<array{date: string, label: string}>, tags: list<string>}>  $updates
     * @return array{globalSummary: string, teamRisks: list<string>}
     */
    private function globalSummary(array $updates, WeeklyCycle $cycle, ?User $user): array
    {
        $this->checkDeadline();
        $json = $this->ask(ReportPipeline::globalSummaryPrompt($updates), ReportSchemas::globalSummary(), $cycle, $user, 'global_summary');

        if (ReportPipeline::needsSpanishRewrite($json)) {
            $json = $this->ask(ReportPipeline::translateGlobalPrompt($json), ReportSchemas::globalSummary(), $cycle, $user, 'translate_global');
        }

        return ReportPipeline::normalizeGlobalSummary($json, $updates);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $metadata
     * @return array<array-key, mixed>
     */
    private function ask(string $prompt, array $schema, WeeklyCycle $cycle, ?User $user, string $operation, array $metadata = []): array
    {
        $response = $this->llm->generate(new LlmRequest(
            feature: AiFeature::WeeklyReport,
            prompt: $prompt,
            responseSchema: $schema,
            user: $user,
            subject: $cycle,
            operation: $operation,
            metadata: ['weekly_cycle_id' => $cycle->id, ...$metadata],
        ));

        return $response->json ?? [];
    }

    /**
     * @param  array{clientId: int|null, clientName: string, executiveSummary: string, status: string, nextSteps: list<string>, milestones: list<array{date: string, label: string}>, tags: list<string>}  $update
     * @param  list<WeeklyProjectSnapshot>  $projects
     * @param  list<ReportClientInput>  $clients
     */
    private function toClientUpdate(array $update, array $projects, array $clients): WeeklyClientUpdate
    {
        $input = null;
        foreach ($clients as $client) {
            if ($client->clientId === $update['clientId']) {
                $input = $client;
                break;
            }
        }

        return new WeeklyClientUpdate(
            clientId: $update['clientId'],
            clientName: $update['clientName'],
            status: WeeklyClientStatus::fromWeeklySync($update['status']),
            executiveSummary: $update['executiveSummary'],
            nextSteps: $update['nextSteps'],
            milestones: array_map(fn (array $milestone): WeeklyMilestone => new WeeklyMilestone($milestone['date'], $milestone['label']), $update['milestones']),
            tags: $update['tags'],
            satisfactionScore: null,
            hasReports: $input?->hasEntries() ?? true,
            projects: $projects,
        );
    }

    private function checkDeadline(): void
    {
        if (microtime(true) >= $this->deadline) {
            throw new LlmUnavailable('La generación de la weekly tardó más de 10 minutos.');
        }
    }

    private function key(?int $clientId): string
    {
        return $clientId === null ? 'general' : (string) $clientId;
    }
}
