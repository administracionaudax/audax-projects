<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tiempo real (D-068, D-070): el audio ya tiene su texto. El cliente cambia «Transcribiendo…» por
 * la transcripción, que pide por su ruta (el texto de un audio de 5 minutos no cabe en un aviso).
 */
final class BroadcastAudioTranscribed implements ShouldBroadcast, ShouldRescue
{
    use Dispatchable;

    public bool $afterCommit = true;

    public function __construct(
        public readonly int $conversationId,
        public readonly int $messageId,
        public readonly int $transcriptionId,
        public readonly string $status,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversation.'.$this->conversationId)];
    }

    public function broadcastAs(): string
    {
        return 'audio.transcribed';
    }

    /**
     * @return array{conversation_id: int, message_id: int, transcription_id: int, status: string}
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'message_id' => $this->messageId,
            'transcription_id' => $this->transcriptionId,
            'status' => $this->status,
        ];
    }
}
