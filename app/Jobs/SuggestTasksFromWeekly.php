<?php

namespace App\Jobs;

use App\Domain\Weeklies\Ai\AiQueue;
use App\Domain\Weeklies\Ai\LlmException;
use App\Domain\Weeklies\Tasks\TaskSuggester;
use App\Enums\WeeklyJobState;
use App\Models\TaskSuggestionBatch;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * «Generar tareas con IA» (F-062, D-204) en la cola `ai` (D-146). Un solo intento: si la IA falla, la
 * tanda queda con el error y se puede volver a pedir. Solo propone: no crea ninguna tarea.
 */
final class SuggestTasksFromWeekly implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = AiQueue::TIMEOUT;

    /** Único (D-222): no se encola otro igual mientras este espera o se ejecuta. */
    public int $uniqueFor = AiQueue::UNIQUE_FOR;

    public function __construct(public readonly int $batchId)
    {
        $this->onQueue(AiQueue::NAME);
    }

    public function uniqueId(): string
    {
        return (string) $this->batchId;
    }

    public function handle(TaskSuggester $suggester): void
    {
        $batch = TaskSuggestionBatch::query()->with(['user', 'cycle'])->find($this->batchId);

        if ($batch === null) {
            return;
        }

        try {
            $suggester->generate($batch);
        } catch (LlmException $e) {
            $batch->forceFill(['state' => WeeklyJobState::Failed, 'error' => $e->userMessage()])->save();
        } catch (Throwable $e) {
            report($e);
            $batch->forceFill(['state' => WeeklyJobState::Failed, 'error' => __('weeklies.errors.llm_unavailable')])->save();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $batch = TaskSuggestionBatch::query()->find($this->batchId);

        if ($batch !== null && $batch->state->isBusy()) {
            $batch->forceFill(['state' => WeeklyJobState::Failed, 'error' => __('weeklies.errors.llm_unavailable')])->save();
        }
    }
}
