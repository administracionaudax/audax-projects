<?php

namespace App\Jobs;

use App\Domain\Weeklies\Ai\AiQueue;
use App\Domain\Weeklies\Assistant\AssistantQuestions;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Responde una pregunta al asistente (F-146, D-206) en la cola `ai` (D-146). Un solo intento: si la IA
 * falla, la pregunta queda con el error y la persona la puede volver a hacer.
 */
final class AnswerAssistantQuestion implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = AiQueue::TIMEOUT;

    /** Único (D-222): no se encola otro igual mientras este espera o se ejecuta. */
    public int $uniqueFor = AiQueue::UNIQUE_FOR;

    public function __construct(public readonly string $questionId)
    {
        $this->onQueue(AiQueue::NAME);
    }

    public function uniqueId(): string
    {
        return (string) $this->questionId;
    }

    public function handle(AssistantQuestions $questions): void
    {
        $questions->answer($this->questionId);
    }
}
