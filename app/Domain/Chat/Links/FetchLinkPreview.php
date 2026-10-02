<?php

namespace App\Domain\Chat\Links;

use App\Domain\Chat\MessageWriter;
use App\Models\Message;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Resuelve la previsualización del primer enlace de un mensaje (D-069) en la cola default, fuera
 * de la petición. Un solo intento: si falla, el mensaje se queda sin previsualización (y la URL,
 * recordada como fallida una hora). Si mientras tanto el mensaje se ha borrado, ocultado o editado
 * con otro enlace, no hace nada.
 */
final class FetchLinkPreview implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 20;

    public function __construct(
        public readonly int $messageId,
        public readonly string $url,
    ) {
        $this->onQueue('default');
    }

    public function handle(LinkPreviewFetcher $fetcher, MessageWriter $writer): void
    {
        $message = Message::query()->find($this->messageId);

        if ($message === null || $message->hidden_at !== null || FirstLink::in($message->body) !== $this->url) {
            return;
        }

        $preview = $fetcher->preview($this->url);

        // Se vuelve a leer: el mensaje ha podido cambiar mientras se descargaba la página.
        $message = Message::query()->find($this->messageId);

        if ($message === null || $message->hidden_at !== null || FirstLink::in($message->body) !== $this->url) {
            return;
        }

        $writer->setLinkPreview($message, $preview?->toArray());
    }
}
