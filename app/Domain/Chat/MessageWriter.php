<?php

namespace App\Domain\Chat;

use App\Domain\Chat\Transcription\AudioTranscriptions;
use App\Domain\Tasks\AttachmentStorage;
use App\Enums\ConversationType;
use App\Enums\MessageType;
use App\Events\Chat\ConversationRead;
use App\Events\Chat\MessagePosted;
use App\Events\Chat\MessageUpdated;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\MessageMention;
use App\Models\MessageReaction;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Chat\ChatNotificationExcerpts;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * ÚNICA forma de escribir en el chat (SPEC §12, D-069). Comprueba la política en cada acción y
 * dispara los eventos que el tiempo real (C2) emite. Los audios se guardan con su transcripción
 * obligatoria (AudioTranscriptions). Todo en una transacción: o se guarda todo o nada.
 */
final class MessageWriter
{
    public const int MAX_BODY = 10_000;

    public function __construct(
        private readonly AttachmentStorage $storage,
        private readonly AudioTranscriptions $transcriptions,
    ) {}

    /**
     * @param  list<UploadedFile>  $files  adjuntos (imágenes, PDF, ofimática…)
     * @param  int|null  $audioDurationMs  duración del audio según el navegador (ya validada): se
     *                                     muestra hasta que el transcriptor mide la real
     */
    public function post(User $author, Conversation $conversation, ?string $body, ?int $parentId = null, array $files = [], ?UploadedFile $audio = null, ?int $audioDurationMs = null): Message
    {
        Gate::forUser($author)->authorize('post', $conversation);

        $body = $body === null ? null : trim($body);
        if (($body === null || $body === '') && $files === [] && $audio === null) {
            throw ValidationException::withMessages(['body' => __('chat.errors.empty')]);
        }
        if ($body !== null && mb_strlen($body) > self::MAX_BODY) {
            throw ValidationException::withMessages(['body' => __('chat.errors.too_long', ['max' => self::MAX_BODY])]);
        }
        if ($parentId !== null && ! $conversation->messages()->withTrashed()->whereKey($parentId)->exists()) {
            throw ValidationException::withMessages(['parent_id' => __('chat.errors.parent')]);
        }

        /** @var list<Attachment> $stored */
        $stored = [];

        try {
            $message = DB::transaction(function () use ($author, $conversation, $body, $parentId, $files, $audio, $audioDurationMs, &$stored): Message {
                // Quien escribe en un canal que ve sin participar pasa a participar (D-270).
                if ($conversation->type->isChannel() && ! $conversation->hasParticipant($author)) {
                    Participants::join($conversation, $author->id);
                }

                $message = $conversation->messages()->create([
                    'user_id' => $author->id,
                    'type' => $audio !== null ? MessageType::Audio : ($body === null || $body === '' ? MessageType::File : MessageType::Text),
                    'body' => $body === '' ? null : $body,
                    'parent_id' => $parentId,
                ]);

                $projectId = $conversation->type === ConversationType::Project ? $conversation->project_id : null;

                if ($audio !== null) {
                    $stored[] = $attachment = $this->storage->store($audio, $message, $projectId, $author, audio: true);
                    $this->transcriptions->forAudio($message, $attachment, $audioDurationMs);
                }

                foreach ($files as $file) {
                    $stored[] = $this->storage->store($file, $message, $projectId, $author);
                }

                $this->syncMentions($message, $conversation);
                $conversation->forceFill(['last_message_at' => $message->created_at])->save();
                $this->advanceRead($conversation, $author->id, $message->id);

                return $message;
            });
        } catch (Throwable $exception) {
            // La transacción deshace las filas, pero no los ficheros ya guardados en el disco
            // (como AttachmentController::store de la Fase 1): se borran para no dejar huérfanos.
            foreach ($stored as $attachment) {
                rescue(fn () => Storage::disk($attachment->disk)->delete($attachment->path), report: false);
            }

            throw $exception;
        }

        MessagePosted::dispatch($message);

        return $message;
    }

    /**
     * Mensaje de sistema (bolsa al 90 %, hito completado…): sin autor ni permisos.
     *
     * @param  array<string, mixed>  $payload
     */
    public function system(Conversation $conversation, string $key, array $payload = []): Message
    {
        $message = DB::transaction(function () use ($conversation, $key, $payload): Message {
            $message = $conversation->messages()->create([
                'type' => MessageType::System,
                'system_key' => $key,
                'system_payload' => $payload,
            ]);
            $conversation->forceFill(['last_message_at' => $message->created_at])->save();

            return $message;
        });

        MessagePosted::dispatch($message);

        return $message;
    }

    public function edit(User $user, Message $message, string $body): Message
    {
        Gate::forUser($user)->authorize('update', $message);

        $body = trim($body);
        if ($body === '' && $message->type === MessageType::Text) {
            throw ValidationException::withMessages(['body' => __('chat.errors.empty')]);
        }
        if (mb_strlen($body) > self::MAX_BODY) {
            throw ValidationException::withMessages(['body' => __('chat.errors.too_long', ['max' => self::MAX_BODY])]);
        }

        DB::transaction(function () use ($message, $body): void {
            $message->forceFill(['body' => $body === '' ? null : $body, 'edited_at' => now()])->save();
            $this->syncMentions($message, $message->conversation);
        });

        MessageUpdated::dispatch($message);

        return $message;
    }

    /**
     * Borrado lógico: queda «Mensaje eliminado» en su sitio (y sus adjuntos dejan de servirse).
     */
    public function delete(User $user, Message $message): void
    {
        Gate::forUser($user)->authorize('delete', $message);

        $message->delete();
        ChatNotificationExcerpts::forget($message->id);

        MessageUpdated::dispatch($message);
    }

    /**
     * Moderación del admin (SPEC §12): oculta o vuelve a mostrar; queda en la auditoría.
     */
    public function setHidden(User $admin, Message $message, bool $hidden): void
    {
        Gate::forUser($admin)->authorize('moderate', $message);

        $message->forceFill(['hidden_at' => $hidden ? now() : null, 'hidden_by' => $hidden ? $admin->id : null])->save();

        // La campana deja de enseñar su texto (al mostrarlo de nuevo no se restaura; D-115).
        if ($hidden) {
            ChatNotificationExcerpts::forget($message->id);
        }

        activity('chat')->causedBy($admin)->performedOn($message)
            ->event($hidden ? 'hidden' : 'unhidden')
            // El texto que tenía al moderarlo, para que la auditoría no dependa del mensaje.
            ->withProperties(['conversation_id' => $message->conversation_id, 'body' => $message->body])
            ->log($hidden ? 'Mensaje ocultado' : 'Mensaje visible de nuevo');

        MessageUpdated::dispatch($message);
    }

    public function setPinned(User $user, Message $message, bool $pinned): void
    {
        Gate::forUser($user)->authorize('pin', $message);

        $message->forceFill(['pinned_at' => $pinned ? now() : null, 'pinned_by' => $pinned ? $user->id : null])->save();

        MessageUpdated::dispatch($message);
    }

    /**
     * Pone o quita la reacción (conmutador). Devuelve si queda puesta.
     */
    public function toggleReaction(User $user, Message $message, string $emoji): bool
    {
        Gate::forUser($user)->authorize('react', $message);

        $emoji = trim($emoji);
        // Solo los emojis del selector (con 0️⃣ o ℹ️, que llevan cifras o letras), nunca texto.
        if ($emoji === '' || mb_strlen($emoji) > 16 || ! EmojiCatalog::contains($emoji)) {
            throw ValidationException::withMessages(['emoji' => __('chat.errors.emoji')]);
        }

        $existing = MessageReaction::query()->where(['message_id' => $message->id, 'user_id' => $user->id, 'emoji' => $emoji])->first();
        if ($existing !== null) {
            $existing->delete();
        } else {
            MessageReaction::query()->create(['message_id' => $message->id, 'user_id' => $user->id, 'emoji' => $emoji]);
        }

        // El mensaje cambia (updated_at): sin tiempo real, la consulta periódica lo recoge.
        $message->touch();

        MessageUpdated::dispatch($message);

        return $existing === null;
    }

    /**
     * Enlaza la tarea creada desde un mensaje de un chat de proyecto (SPEC §12: el mensaje queda
     * enlazado a la tarea). Quien la crea tiene que poder ver la conversación y crear tareas en ese
     * proyecto; un mensaje borrado u ocultado, o que ya tiene tarea, no se enlaza.
     */
    public function linkTask(User $user, Message $message, Task $task): void
    {
        $conversation = $message->conversation;
        Gate::forUser($user)->authorize('view', $conversation);

        if ($conversation->type !== ConversationType::Project
            || $conversation->project_id === null
            || $task->project_id !== $conversation->project_id
            || $message->trashed()
            || $message->hidden_at !== null
            || $message->type === MessageType::System
            || ($message->task_id !== null && $message->task_id !== $task->id)) {
            throw ValidationException::withMessages(['message' => __('conversations.errors.task_link')]);
        }

        Gate::forUser($user)->authorize('create', [Task::class, $conversation->project]);

        $message->forceFill(['task_id' => $task->id])->save();

        MessageUpdated::dispatch($message);
    }

    /**
     * Guarda (o quita) la previsualización del primer enlace (D-069). La escribe el job de
     * App\Domain\Chat\Links, sin usuario: solo cambia si es distinta de la que había.
     *
     * @param  array{url: string, title: string, description: string|null, domain: string}|null  $preview
     */
    public function setLinkPreview(Message $message, ?array $preview): void
    {
        if ($message->link_preview === $preview) {
            return;
        }

        $message->forceFill(['link_preview' => $preview])->save();

        MessageUpdated::dispatch($message);
    }

    /**
     * Marca como leído hasta ese mensaje (nunca hacia atrás).
     */
    public function markRead(User $user, Conversation $conversation, int $messageId): void
    {
        Gate::forUser($user)->authorize('view', $conversation);

        $participant = $this->advanceRead($conversation, $user->id, $messageId);
        if ($participant !== null) {
            ConversationRead::dispatch($participant);
        }
    }

    private function advanceRead(Conversation $conversation, int $userId, int $messageId): ?ConversationParticipant
    {
        $participant = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->first();

        if ($participant === null || ($participant->last_read_message_id ?? 0) >= $messageId) {
            return null;
        }

        $participant->forceFill(['last_read_message_id' => $messageId])->save();

        return $participant;
    }

    /**
     * Menciones válidas: solo participantes activos (y @todos). Se rehacen al editar.
     */
    private function syncMentions(Message $message, Conversation $conversation): void
    {
        $parsed = Mentions::parse((string) $message->body);

        // En un canal, a quien se menciona y lo ve sin participar se le hace entrar (D-270): si no,
        // la mención no le llegaría.
        if ($conversation->type->isChannel() && $parsed['users'] !== []) {
            $outside = User::query()->whereKey($parsed['users'])
                ->whereKeyNot($message->user_id ?? 0)
                ->whereNotIn('id', $conversation->activeParticipants()->select('user_id'))
                ->get();
            foreach ($outside as $person) {
                if (ConversationAccess::canView($person, $conversation)) {
                    Participants::join($conversation, $person->id);
                }
            }
        }
        $valid = $parsed['users'] === [] ? [] : $conversation->activeParticipants()
            ->whereIn('user_id', $parsed['users'])
            ->where('user_id', '!=', $message->user_id)
            ->pluck('user_id')->all();

        MessageMention::query()->where('message_id', $message->id)->delete();

        foreach ($valid as $userId) {
            MessageMention::query()->create(['message_id' => $message->id, 'user_id' => (int) $userId, 'everyone' => false]);
        }
        if ($parsed['everyone']) {
            MessageMention::query()->create(['message_id' => $message->id, 'user_id' => null, 'everyone' => true]);
        }
    }
}
