<?php

namespace App\Http\Resources\Chat;

use App\Models\Conversation;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Qué mensajes se cargan (SPEC §12: paginación infinita hacia atrás). Siempre por cursor sobre el
 * id con el índice (conversation_id, id), nunca con OFFSET: cuesta lo mismo en la página 1 que en
 * la 500. Los borrados se incluyen (se pintan como «Mensaje eliminado» en su sitio).
 * Cada consulta pide uno más de la página para saber si hay más sin contarlos.
 */
final class MessageWindow
{
    public const int PAGE = 30;

    /** Mensajes a cada lado del que se busca (enlace a un mensaje o cita de un hilo). */
    public const int AROUND = 15;

    /** Mensajes nuevos por consulta periódica; si hay más, el navegador recarga los últimos. */
    public const int POLL_LIMIT = 50;

    /** Mensajes cambiados por consulta periódica (en la ventana que tiene cargada el navegador). */
    public const int CHANGES_LIMIT = 200;

    public const int PINNED_LIMIT = 50;

    /**
     * Los más recientes.
     *
     * @return array{0: EloquentCollection<int, Message>, 1: bool, 2: bool} mensajes (del más antiguo al más nuevo), ¿hay anteriores?, ¿hay posteriores?
     */
    public function latest(Conversation $conversation): array
    {
        [$messages, $older] = $this->backwards($this->base($conversation), self::PAGE);

        return [$messages, $older, false];
    }

    /**
     * @return array{0: EloquentCollection<int, Message>, 1: bool, 2: bool}
     */
    public function before(Conversation $conversation, int $id): array
    {
        [$messages, $older] = $this->backwards($this->base($conversation)->where('id', '<', $id), self::PAGE);

        return [$messages, $older, true];
    }

    /**
     * @return array{0: EloquentCollection<int, Message>, 1: bool, 2: bool}
     */
    public function after(Conversation $conversation, int $id): array
    {
        [$messages, $newer] = $this->forwards($this->base($conversation)->where('id', '>', $id), self::PAGE);

        return [$messages, true, $newer];
    }

    /**
     * El mensaje $id con AROUND a cada lado (el mensaje tiene que ser de la conversación).
     *
     * @return array{0: EloquentCollection<int, Message>, 1: bool, 2: bool}
     */
    public function around(Conversation $conversation, int $id): array
    {
        [$older, $hasOlder] = $this->backwards($this->base($conversation)->where('id', '<=', $id), self::AROUND + 1);
        [$newer, $hasNewer] = $this->forwards($this->base($conversation)->where('id', '>', $id), self::AROUND);

        return [$older->concat($newer), $hasOlder, $hasNewer];
    }

    /**
     * Consulta periódica (sin tiempo real, o como red de seguridad con él): los mensajes nuevos
     * tras $afterId y los que han cambiado desde $since dentro de la ventana [$fromId, $afterId]
     * que tiene cargada el navegador (editados, borrados, ocultados, fijados, reacciones,
     * previsualizaciones y transcripciones terminadas). Sin tocar el resto de la conversación.
     *
     * @return array{new: EloquentCollection<int, Message>, updated: EloquentCollection<int, Message>, has_more: bool}
     */
    public function changes(Conversation $conversation, int $afterId, int $fromId, ?CarbonImmutable $since): array
    {
        [$new, $more] = $this->forwards($this->base($conversation)->where('id', '>', $afterId), self::POLL_LIMIT);

        $updated = $since === null || $fromId < 1 || $fromId > $afterId
            ? new EloquentCollection
            : $this->base($conversation)
                ->whereBetween('id', [$fromId, $afterId])
                ->where(fn (Builder $query) => $query->where('updated_at', '>=', $since)
                    ->orWhereHas('transcription', fn (Builder $transcription) => $transcription->where('updated_at', '>=', $since)))
                ->orderBy('id')
                ->limit(self::CHANGES_LIMIT)
                ->get();

        return ['new' => $new, 'updated' => $updated, 'has_more' => $more];
    }

    /**
     * Mensajes fijados (barra de fijados), los últimos primero. Los ocultados solo para quien modera.
     *
     * @return EloquentCollection<int, Message>
     */
    public function pinned(Conversation $conversation, bool $withHidden): EloquentCollection
    {
        return Message::query()
            ->where('conversation_id', $conversation->id)
            ->whereNotNull('pinned_at')
            ->when(! $withHidden, fn (Builder $query) => $query->whereNull('hidden_at'))
            ->orderByDesc('pinned_at')
            ->orderByDesc('id')
            ->limit(self::PINNED_LIMIT)
            ->get(['id', 'conversation_id', 'user_id', 'type', 'body', 'system_key', 'system_payload', 'hidden_at', 'pinned_at', 'pinned_by', 'created_at']);
    }

    /**
     * @return Builder<Message>
     */
    private function base(Conversation $conversation): Builder
    {
        return Message::query()->withTrashed()->where('conversation_id', $conversation->id);
    }

    /**
     * @param  Builder<Message>  $query
     * @return array{0: EloquentCollection<int, Message>, 1: bool}
     */
    private function backwards(Builder $query, int $limit): array
    {
        $rows = $query->orderByDesc('id')->limit($limit + 1)->get();

        return [$rows->take($limit)->reverse()->values(), $rows->count() > $limit];
    }

    /**
     * @param  Builder<Message>  $query
     * @return array{0: EloquentCollection<int, Message>, 1: bool}
     */
    private function forwards(Builder $query, int $limit): array
    {
        $rows = $query->orderBy('id')->limit($limit + 1)->get();

        return [$rows->take($limit)->values(), $rows->count() > $limit];
    }
}
