<?php

namespace App\Events\Weeklies;

use App\Models\Dictation;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tiempo real del dictado (F-049, D-158): el dictado ha terminado (con texto, sin voz o fallido). Va
 * al canal privado de su autor (App.Models.User.{id}); la interfaz cambia «Transcribiendo…» por el
 * texto, que pide a `dictations.show`. Sin Reverb, la interfaz sondea esa misma ruta.
 */
final class DictationUpdated implements ShouldBroadcast, ShouldRescue
{
    use Dispatchable;

    public bool $afterCommit = true;

    public function __construct(
        public readonly int $userId,
        public readonly int $dictationId,
        public readonly string $status,
        public readonly ?string $warning,
    ) {}

    public static function for(Dictation $dictation): self
    {
        return new self($dictation->user_id, $dictation->id, $dictation->status->value, $dictation->warning);
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'dictation.updated';
    }

    /**
     * @return array{dictation_id: int, status: string, warning: string|null}
     */
    public function broadcastWith(): array
    {
        return [
            'dictation_id' => $this->dictationId,
            'status' => $this->status,
            'warning' => $this->warning,
        ];
    }
}
