<?php

namespace App\Jobs;

use App\Domain\Weeklies\Ai\AiQueue;
use App\Domain\Weeklies\Ai\LlmException;
use App\Domain\Weeklies\Insights\AiSummaries;
use App\Enums\WeeklyJobState;
use App\Models\AiSummary;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Genera un resumen con IA de una ficha (cliente o persona) en la cola `ai` (D-146 y D-194). Un solo
 * intento: si la IA falla, el resumen queda con el error y se puede volver a pedir.
 */
final class GenerateAiSummary implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = AiQueue::TIMEOUT;

    public function __construct(
        public readonly int $summaryId,
        public readonly ?int $userId = null,
    ) {
        $this->onQueue(AiQueue::NAME);
    }

    public function handle(AiSummaries $summaries): void
    {
        $summary = AiSummary::query()->find($this->summaryId);

        if ($summary === null) {
            return;
        }

        if ($summary->subject === null) {
            $summary->forceFill(['state' => WeeklyJobState::Failed, 'error' => __('weeklies.insights.subject_missing')])->save();

            return;
        }

        try {
            $summaries->generate($summary, $this->userId === null ? null : User::query()->find($this->userId));
        } catch (LlmException $e) {
            $summary->forceFill(['state' => WeeklyJobState::Failed, 'error' => $e->userMessage()])->save();
        } catch (Throwable $e) {
            report($e);
            $summary->forceFill(['state' => WeeklyJobState::Failed, 'error' => __('weeklies.errors.llm_unavailable')])->save();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $summary = AiSummary::query()->find($this->summaryId);

        if ($summary !== null && $summary->state->isBusy()) {
            $summary->forceFill(['state' => WeeklyJobState::Failed, 'error' => __('weeklies.errors.llm_unavailable')])->save();
        }
    }
}
