<?php

namespace App\Http\Resources\Chat;

use App\Enums\MessageType;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Mensajes para el navegador (contrato: resources/js/types/chat.ts, ChatMessage) con un número
 * fijo de consultas por página, sin importar cuántos mensajes haya: padres de los hilos,
 * reacciones, adjuntos, transcripciones y tareas con carga anticipada, y TODAS las personas
 * (autores, citas, reacciones, menciones, quien fijó u ocultó) en una sola consulta.
 *
 * Lo que se ve de cada mensaje (D-069, D-071):
 * - borrado: solo «Mensaje eliminado» (sin cuerpo, adjuntos, reacciones ni cita),
 * - ocultado por un admin: igual para todos salvo para quien modera, que lo ve con el aviso,
 * - de sistema: sin autor ni cuerpo; la interfaz pinta el aviso con su clave y sus datos.
 * Los permisos de cada mensaje salen de ConversationAbilities (calculadas una vez).
 */
final class MessagePresenter
{
    public const int QUOTE_LENGTH = 120;

    public function __construct(
        private readonly User $viewer,
        private readonly ConversationAbilities $can,
    ) {}

    /**
     * @param  EloquentCollection<int, Message>  $messages  de una conversación, con los borrados
     * @return array{messages: list<array<string, mixed>>, users: list<array{id: int, name: string, avatar: string|null, is_active: bool}>}
     */
    public function present(EloquentCollection $messages): array
    {
        if ($messages->isEmpty()) {
            return ['messages' => [], 'users' => []];
        }

        $messages->load([
            'parent' => fn (Relation $query) => $query->select(['id', 'conversation_id', 'user_id', 'type', 'body', 'system_key', 'system_payload', 'hidden_at', 'deleted_at']),
            'reactions' => fn (Relation $query) => $query->select(['id', 'message_id', 'user_id', 'emoji'])->orderBy('id'),
            'attachments' => fn (Relation $query) => $query->orderBy('id'),
            'transcription',
            'task' => fn (Relation $query) => $query->select(['id', 'title', 'project_id']),
        ]);

        $users = ChatUsers::load($this->userIds($messages));

        return [
            'messages' => array_values($messages->map(fn (Message $message): array => $this->message($message, $users))->all()),
            'users' => ChatUsers::list($users),
        ];
    }

    /**
     * @param  EloquentCollection<int, Message>  $messages
     * @return list<int|null>
     */
    private function userIds(EloquentCollection $messages): array
    {
        $ids = [];

        foreach ($messages as $message) {
            array_push($ids, $message->user_id, $message->hidden_by, $message->pinned_by, ...MessagePreview::mentionIds($message->body));

            $parent = $message->parent;
            if ($parent !== null) {
                array_push($ids, $parent->user_id, ...MessagePreview::mentionIds($parent->body));
            }

            foreach ($message->reactions as $reaction) {
                $ids[] = $reaction->user_id;
            }
        }

        return $ids;
    }

    /**
     * @param  array<int, User>  $users
     * @return array<string, mixed>
     */
    private function message(Message $message, array $users): array
    {
        $deleted = $message->trashed();
        $hidden = $message->hidden_at !== null;
        $system = $message->type === MessageType::System;
        $content = ! $deleted && (! $hidden || $this->can->moderate);
        $alive = ! $deleted;
        $mine = $message->user_id !== null && $message->user_id === $this->viewer->id;
        $author = $message->user_id === null ? null : ($users[$message->user_id] ?? null);

        [$audio, $files] = $content ? $this->media($message) : [null, []];

        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'type' => $message->type->value,
            'author' => $author === null ? null : ChatUsers::present($author),
            'body' => $content && ! $system ? $message->body : null,
            'created_at' => $message->created_at?->toIso8601ZuluString(),
            'edited_at' => $alive ? $message->edited_at?->toIso8601ZuluString() : null,
            'deleted' => $deleted,
            'hidden' => $hidden && $alive,
            'hidden_by' => $hidden && $alive && $this->can->moderate && $message->hidden_by !== null ? ($users[$message->hidden_by] ?? null)?->name : null,
            'pinned' => $alive && $message->pinned_at !== null,
            'pinned_by' => $alive && $message->pinned_by !== null ? ($users[$message->pinned_by] ?? null)?->name : null,
            'parent' => $content ? $this->parent($message, $users) : null,
            'reactions' => $content ? $this->reactions($message, $users) : [],
            'attachments' => $files,
            'audio' => $audio,
            'task' => $content && $message->task !== null ? ['id' => $message->task->id, 'title' => $message->task->title] : null,
            'link_preview' => $content ? $message->link_preview : null,
            'system' => $system ? ['key' => (string) $message->system_key, 'payload' => (object) ($message->system_payload ?? [])] : null,
            'can' => [
                'edit' => $mine && ! $system && ! $hidden && $alive && $this->can->post,
                'delete' => $mine && ! $system && $alive && $this->can->view,
                'reply' => $alive && ! $hidden && $this->can->post,
                'react' => $alive && ! $hidden && $this->can->post,
                'pin' => $alive && $this->can->post,
                'moderate' => $alive && ! $system && $this->can->moderate,
                'create_task' => $alive && ! $hidden && ! $system && $message->task_id === null && $this->can->createTask,
            ],
        ];
    }

    /**
     * El audio (con su transcripción) y el resto de adjuntos.
     *
     * @return array{0: array<string, mixed>|null, 1: list<array<string, mixed>>}
     */
    private function media(Message $message): array
    {
        $audio = null;
        $files = [];

        foreach ($message->attachments as $attachment) {
            if ($audio === null && $message->type === MessageType::Audio && $attachment->isAudio()) {
                $audio = ChatAttachments::audio($attachment, $message->transcription);

                continue;
            }

            $files[] = ChatAttachments::present($attachment);
        }

        return [$audio, $files];
    }

    /**
     * La cita del mensaje al que responde (hilo): autor y extracto, o que ya no está.
     *
     * @param  array<int, User>  $users
     * @return array<string, mixed>|null
     */
    private function parent(Message $message, array $users): ?array
    {
        $parent = $message->parent;

        if ($parent === null) {
            return null;
        }

        $deleted = $parent->trashed();
        $hidden = $parent->hidden_at !== null;
        $system = $parent->type === MessageType::System;
        $visible = ! $deleted && (! $hidden || $this->can->moderate);

        return [
            'id' => $parent->id,
            'type' => $parent->type->value,
            'author' => $parent->user_id === null ? null : ($users[$parent->user_id] ?? null)?->name,
            'excerpt' => $visible && ! $system ? MessagePreview::plain($parent->body, $users, self::QUOTE_LENGTH) : null,
            'deleted' => $deleted,
            'hidden' => $hidden && ! $deleted,
            'system' => $visible && $system ? ['key' => (string) $parent->system_key, 'payload' => (object) ($parent->system_payload ?? [])] : null,
        ];
    }

    /**
     * Reacciones agrupadas por emoji, en el orden en que se pusieron: recuento, si quien mira ha
     * reaccionado y quién (para el tooltip).
     *
     * @param  array<int, User>  $users
     * @return list<array{emoji: string, count: int, reacted: bool, users: list<string>}>
     */
    private function reactions(Message $message, array $users): array
    {
        $groups = [];

        /** @var MessageReaction $reaction */
        foreach ($message->reactions as $reaction) {
            $group = $groups[$reaction->emoji] ?? ['emoji' => $reaction->emoji, 'count' => 0, 'reacted' => false, 'users' => []];
            $group['count']++;
            $group['reacted'] = $group['reacted'] || $reaction->user_id === $this->viewer->id;
            $group['users'][] = $users[$reaction->user_id]->name ?? __('conversations.unknown_person');
            $groups[$reaction->emoji] = $group;
        }

        return array_values($groups);
    }
}
