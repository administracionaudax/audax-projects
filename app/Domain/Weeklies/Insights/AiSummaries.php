<?php

namespace App\Domain\Weeklies\Insights;

use App\Domain\Weeklies\Ai\LlmClient;
use App\Domain\Weeklies\Ai\LlmRequest;
use App\Enums\AiSummaryKind;
use App\Enums\WeeklyJobState;
use App\Http\Resources\UserSummaryResource;
use App\Jobs\GenerateAiSummary;
use App\Models\AiSummary;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Los resúmenes con IA de las fichas (F-129, F-131, F-144 y F-145; D-194): se piden a mano, se
 * generan en la cola `ai` (GenerateAiSummary, D-146) con los prompts de WeeklySync (InsightPrompts)
 * y se guardan en ai_summaries hasta que alguien los regenera. Cada llamada a Gemini queda en
 * ai_usage (GeminiClient), con quien la pidió y sobre qué.
 *
 * Solo se manda a Gemini lo que puede ver quien lo pide: los reportes enviados y el estado de los
 * proyectos los ve toda la plantilla (D-021); los de una persona solo se piden con
 * view-person-ai-summary (D-147), que comprueba el controlador.
 */
final class AiSummaries
{
    /** Un resumen «en cola» o «generando» sin cambios desde hace más se da por atascado (como D-190). */
    public const int STUCK_MINUTES = 12;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly ClientInsights $clients,
        private readonly PersonInsights $people,
    ) {}

    public function find(AiSummaryKind $kind, Model $subject): ?AiSummary
    {
        return AiSummary::query()
            ->where('kind', $kind->value)
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->with('requester')
            ->first();
    }

    /**
     * Pide un resumen nuevo y lo encola. Si ya hay uno en marcha (y no atascado), no encola otro.
     */
    public function request(AiSummaryKind $kind, Model $subject, User $user): AiSummary
    {
        if (! $subject instanceof ($kind->subjectClass())) {
            throw new InvalidArgumentException("El resumen {$kind->value} no es de ".$subject::class);
        }

        $summary = $this->find($kind, $subject);

        if ($summary !== null && self::isBusy($summary)) {
            return $summary;
        }

        $summary ??= new AiSummary([
            'kind' => $kind,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
        ]);

        $summary->fill(['state' => WeeklyJobState::Queued, 'error' => null, 'requested_by' => $user->id]);
        $summary->updated_at = now();
        $summary->save();

        GenerateAiSummary::dispatch($summary->id, $user->id);

        return $summary->load('requester');
    }

    /** En cola o generando, y no atascado. */
    public static function isBusy(AiSummary $summary): bool
    {
        return $summary->state->isBusy()
            && $summary->updated_at !== null
            && $summary->updated_at->greaterThan(now()->subMinutes(self::STUCK_MINUTES));
    }

    /**
     * Genera el resumen (lo llama el Job). Las excepciones de la IA suben al Job, que deja el error.
     */
    public function generate(AiSummary $summary, ?User $user): void
    {
        $summary->forceFill(['state' => WeeklyJobState::Running, 'error' => null])->save();
        $subject = $summary->subject;
        $content = null;
        $items = null;
        $model = null;

        switch ($summary->kind) {
            case AiSummaryKind::ClientSummary:
                /** @var Client $subject */
                $weeks = $this->clients->summaryWeeks($subject);

                if ($weeks === []) {
                    $content = InsightPrompts::INSUFFICIENT_CLIENT_HISTORY;
                    break;
                }

                $context = $this->clients->summaryContext($subject);
                $response = $this->llm->generate(new LlmRequest(
                    feature: $summary->kind->feature(),
                    prompt: InsightPrompts::clientSummary($subject->name, $subject->satisfaction_score, $context['owner']?->name, $context['collaborators'], $weeks, $context['projects']),
                    user: $user,
                    subject: $subject,
                    operation: 'client_summary',
                    metadata: ['client_id' => $subject->id, 'weeks' => count($weeks)],
                ));
                $content = InsightPrompts::stripCodeFences($response->text);
                $model = $response->model;
                break;

            case AiSummaryKind::ClientTeamActivity:
                /** @var Client $subject */
                $payload = $this->clients->teamActivityPayload($subject);
                $items = [];

                if ($payload !== []) {
                    $response = $this->llm->generate(new LlmRequest(
                        feature: $summary->kind->feature(),
                        prompt: InsightPrompts::teamActivity($subject->name, $payload),
                        responseSchema: InsightPrompts::summariesSchema('memberId'),
                        user: $user,
                        subject: $subject,
                        operation: 'team_activity',
                        metadata: ['client_id' => $subject->id, 'members' => count($payload)],
                    ));
                    $items = InsightPrompts::summariesById($response->json, 'memberId', self::hasReports($payload, 'memberId'));
                    $model = $response->model;
                }
                break;

            case AiSummaryKind::PersonPerformance:
                /** @var User $subject */
                $history = $this->people->performancePayload($subject);

                if ($history === []) {
                    $content = InsightPrompts::INSUFFICIENT_PERFORMANCE_HISTORY;
                    break;
                }

                $response = $this->llm->generate(new LlmRequest(
                    feature: $summary->kind->feature(),
                    prompt: InsightPrompts::performance($subject->name, $history),
                    user: $user,
                    subject: $subject,
                    operation: 'performance_summary',
                    metadata: ['target_user_id' => $subject->id, 'weeks' => count($history)],
                ));
                $content = trim($response->text);
                $model = $response->model;
                break;

            case AiSummaryKind::PersonClientActivity:
                /** @var User $subject */
                $payload = $this->people->clientActivityPayload($subject);
                $items = [];

                if ($payload !== []) {
                    $response = $this->llm->generate(new LlmRequest(
                        feature: $summary->kind->feature(),
                        prompt: InsightPrompts::personClientActivity($subject->name ?: 'Usuario', $payload),
                        responseSchema: InsightPrompts::summariesSchema('clientId'),
                        user: $user,
                        subject: $subject,
                        operation: 'client_activity',
                        metadata: ['target_user_id' => $subject->id, 'clients' => count($payload)],
                    ));
                    $items = InsightPrompts::summariesById($response->json, 'clientId', self::hasReports($payload, 'clientId'));
                    $model = $response->model;
                }
                break;
        }

        $summary->forceFill([
            'state' => WeeklyJobState::Done,
            'content' => $content,
            'items' => $items,
            'error' => null,
            'model' => $model,
            'generated_at' => now(),
        ])->save();
    }

    /**
     * Lo que recibe la página.
     *
     * @return array{kind: string, state: string, stuck: bool, content: string|null, items: array<string, string>|null, error: string|null, generated_at: string|null, requested_by: array<string, mixed>|null}|null
     */
    public static function present(?AiSummary $summary): ?array
    {
        if ($summary === null) {
            return null;
        }

        return [
            'kind' => $summary->kind->value,
            'state' => $summary->state->value,
            'stuck' => $summary->state->isBusy() && ! self::isBusy($summary),
            'content' => $summary->content,
            'items' => $summary->items,
            'error' => $summary->error,
            'generated_at' => $summary->generated_at?->toIso8601String(),
            'requested_by' => $summary->requester === null ? null : (new UserSummaryResource($summary->requester))->resolve(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $payload
     * @return array<string, bool>
     */
    private static function hasReports(array $payload, string $idKey): array
    {
        $result = [];

        foreach ($payload as $row) {
            $result[(string) $row[$idKey]] = ($row['reports'] ?? []) !== [];
        }

        return $result;
    }
}
