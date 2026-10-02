<?php

namespace App\Domain\Privacy\Export\Sections;

use App\Enums\ConversationType;
use App\Enums\MessageType;
use App\Enums\TranscriptionStatus;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;

/**
 * Mensajes propios del chat (Fase 6, D-075): conversación, fecha y texto, los nombres de sus
 * adjuntos y la transcripción de los audios propios. Nunca los mensajes de otras personas ni los
 * de sistema. Incluye los borrados (siguen guardados) y los ocultados por el admin, con su fecha.
 * Las menciones <@ID> se escriben como @Nombre; las conversaciones directas se nombran con la otra
 * persona (es con quien hablaba).
 */
final class ChatMessagesSection extends Section
{
    public const int CHUNK = 500;

    /** @var array<int, string> id → nombre de las personas mencionadas */
    private array $names = [];

    public function key(): string
    {
        return 'mensajes-chat';
    }

    protected function textKey(): string
    {
        return 'chat_messages';
    }

    protected function columnKeys(): array
    {
        return ['id', 'conversation', 'type', 'body', 'attachments', 'transcription', 'reply_to', 'created_at', 'edited_at', 'deleted_at', 'hidden_at'];
    }

    public function rows(User $user): iterable
    {
        $this->names = [];
        $conversations = $this->conversationNames($user);

        $messages = Message::query()
            ->withTrashed()
            ->where('user_id', $user->id)
            ->where('type', '!=', MessageType::System->value)
            ->with([
                'attachments' => fn ($attachments) => $attachments->withTrashed()->select(['id', 'attachable_type', 'attachable_id', 'original_name']),
                'transcription:id,message_id,status,text',
            ])
            ->lazyById(self::CHUNK);

        foreach ($messages as $message) {
            $transcription = $message->transcription;

            yield [
                'id' => $message->id,
                'conversation' => $conversations[$message->conversation_id] ?? null,
                'type' => self::text("privacy.export.chat.types.{$message->type->value}"),
                'body' => $message->body === null ? null : $this->plain($message->body),
                'attachments' => $message->attachments->isEmpty() ? null : $message->attachments->map(fn (Attachment $attachment): string => $attachment->original_name)->implode(', '),
                'transcription' => $transcription !== null && $transcription->status === TranscriptionStatus::Done ? $transcription->text : null,
                'reply_to' => $message->parent_id,
                'created_at' => self::instant($message->created_at),
                'edited_at' => self::instant($message->edited_at),
                'deleted_at' => self::instant($message->deleted_at),
                'hidden_at' => self::instant($message->hidden_at),
            ];
        }
    }

    /**
     * Nombre de cada conversación en la que la persona ha escrito (una consulta para las
     * conversaciones y otra para la otra persona de las directas).
     *
     * @return array<int, string>
     */
    private function conversationNames(User $user): array
    {
        $ids = Message::query()->withTrashed()->where('user_id', $user->id)->distinct()->pluck('conversation_id')->all();

        if ($ids === []) {
            return [];
        }

        $conversations = Conversation::query()->whereKey($ids)
            ->with(['project' => fn ($project) => $project->withTrashed()->select(['id', 'code', 'name'])])
            ->get(['id', 'type', 'name', 'project_id']);

        $others = ConversationParticipant::query()
            ->whereIn('conversation_id', $conversations->where('type', ConversationType::Direct)->modelKeys())
            ->where('user_id', '!=', $user->id)
            ->with('user:id,name')
            ->get(['conversation_id', 'user_id'])
            ->keyBy('conversation_id');

        $names = [];

        foreach ($conversations as $conversation) {
            $project = $conversation->project;

            $names[$conversation->id] = match ($conversation->type) {
                ConversationType::Project => self::text('privacy.export.chat.project', ['project' => $project !== null ? "{$project->code} · {$project->name}" : '—']),
                ConversationType::Group => self::text('privacy.export.chat.group', ['name' => (string) $conversation->name]),
                ConversationType::Direct => self::text('privacy.export.chat.direct', ['person' => $others->get($conversation->id)?->user->name ?? '—']),
            };
        }

        return $names;
    }

    /**
     * Texto del mensaje con las menciones <@ID> como @Nombre (los nombres se buscan una vez).
     */
    private function plain(string $body): string
    {
        preg_match_all('/<@(\d{1,10})>/', $body, $matches);
        $missing = array_values(array_diff(array_map('intval', $matches[1]), array_keys($this->names)));

        if ($missing !== []) {
            /** @var array<int, string> $found */
            $found = User::query()->whereKey($missing)->pluck('name', 'id')->all();

            foreach ($missing as $id) {
                $this->names[$id] = $found[$id] ?? self::text('privacy.export.chat.someone');
            }
        }

        return (string) preg_replace_callback('/<@(\d{1,10})>/', fn (array $match): string => '@'.$this->names[(int) $match[1]], $body);
    }
}
