<?php

namespace App\Listeners\Chat;

use App\Broadcasting\ChatNoticeThrottle;
use App\Broadcasting\Realtime;
use App\Events\Chat\AudioTranscribed;
use App\Events\Chat\BroadcastAudioTranscribed;
use App\Events\Chat\BroadcastConversationActivity;
use App\Events\Chat\BroadcastConversationRead;
use App\Events\Chat\BroadcastMessagePosted;
use App\Events\Chat\BroadcastMessageUpdated;
use App\Events\Chat\ConversationRead;
use App\Events\Chat\MessagePosted;
use App\Events\Chat\MessageUpdated;
use App\Models\ConversationParticipant;
use App\Models\Message;
use Illuminate\Database\Eloquent\Builder;

/**
 * Del dominio del chat al tiempo real (D-068). Los eventos del contrato (MessageWriter y el job de
 * transcripción) se convierten en eventos de broadcast que salen POR LA COLA a:
 * - conversation.{id}: mensaje nuevo, mensaje cambiado, leído y audio transcrito,
 * - App.Models.User.{id} de cada participante (salvo el autor y quien quede fuera del alcance de la
 *   conversación, como un colaborador externo en una directa, D-134): actividad para los contadores,
 * - App.Models.User.{id} de quien lee: su lectura, para sus otras pestañas.
 *
 * Es síncrono a propósito: solo prepara ids (la conversación y sus participantes) y encola; así
 * la pista de qué cambió en un mensaje (wasChanged) se toma en el momento. Sin tiempo real
 * (Realtime::enabled) no se emite nada. Al leer una conversación, además, se reinicia la
 * agrupación de avisos de esa persona en ella (con o sin tiempo real).
 */
final class ChatRealtimeRelay
{
    public function __construct(private readonly ChatNoticeThrottle $throttle) {}

    public function handleMessagePosted(MessagePosted $event): void
    {
        if (! Realtime::enabled()) {
            return;
        }

        $message = $event->message;

        event(BroadcastMessagePosted::fromMessage($message));

        $conversation = $message->conversation;
        $recipients = array_values(ConversationParticipant::query()
            ->where('conversation_id', $message->conversation_id)
            ->whereNull('left_at')
            // Red de seguridad (D-134): nadie fuera del alcance de la conversación.
            ->whereHas('user', fn (Builder $query) => $query->withinConversationScope($conversation))
            ->when($message->user_id !== null, fn ($query) => $query->where('user_id', '!=', $message->user_id))
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());

        if ($recipients !== []) {
            event(new BroadcastConversationActivity($message->conversation_id, $message->id, $message->user_id, $recipients));
        }
    }

    public function handleMessageUpdated(MessageUpdated $event): void
    {
        if (Realtime::enabled()) {
            event(BroadcastMessageUpdated::fromMessage($event->message));
        }
    }

    public function handleConversationRead(ConversationRead $event): void
    {
        $this->throttle->reset($event->participant->user_id, $event->participant->conversation_id);

        if (Realtime::enabled()) {
            event(BroadcastConversationRead::fromParticipant($event->participant));
        }
    }

    public function handleAudioTranscribed(AudioTranscribed $event): void
    {
        if (! Realtime::enabled()) {
            return;
        }

        $transcription = $event->transcription;
        $conversationId = Message::query()->withTrashed()->whereKey($transcription->message_id)->value('conversation_id');

        if ($conversationId === null) {
            return;
        }

        event(new BroadcastAudioTranscribed((int) $conversationId, $transcription->message_id, $transcription->id, $transcription->status->value));
    }
}
