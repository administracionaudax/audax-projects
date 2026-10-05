<?php

namespace App\Events\Weeklies;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tiempo real del asistente (F-146, D-206): la respuesta (o el error) de una pregunta ya está. Va al
 * canal privado de quien pregunta (App.Models.User.{id}) sin el texto; la página lo pide a
 * `assistant.questions.show`. Sin Reverb, la página pregunta por ella cada pocos segundos.
 */
final class AssistantAnswered implements ShouldBroadcast, ShouldRescue
{
    use Dispatchable;

    public function __construct(
        public readonly int $userId,
        public readonly string $questionId,
        public readonly string $state,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'assistant.answered';
    }

    /**
     * @return array{question_id: string, state: string}
     */
    public function broadcastWith(): array
    {
        return ['question_id' => $this->questionId, 'state' => $this->state];
    }
}
