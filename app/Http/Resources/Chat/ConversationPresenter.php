<?php

namespace App\Http\Resources\Chat;

use App\Enums\ConversationType;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Project;
use App\Models\User;

/**
 * Cabecera y datos de una conversación abierta (contrato: resources/js/types/chat.ts,
 * ChatConversation): nombre (proyecto, persona o grupo), participantes activos con lo que han
 * leído (autocompletado de menciones y «leído por») y lo que quien mira puede hacer.
 */
final class ConversationPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function detail(Conversation $conversation, User $viewer, ConversationAbilities $can): array
    {
        $participants = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->whereNull('left_at')
            ->get(['id', 'conversation_id', 'user_id', 'last_read_message_id']);
        $users = ChatUsers::load($participants->pluck('user_id')->all());
        $project = $conversation->type === ConversationType::Project ? $conversation->project : null;
        $client = self::clientOf($conversation, $project);
        $other = null;

        if ($conversation->type === ConversationType::Direct) {
            $otherId = $participants->first(fn (ConversationParticipant $participant): bool => $participant->user_id !== $viewer->id)?->user_id;
            $other = $otherId === null ? null : ($users[$otherId] ?? null);
        }

        $list = $participants
            ->filter(fn (ConversationParticipant $participant): bool => isset($users[$participant->user_id]))
            ->map(fn (ConversationParticipant $participant): array => [
                ...ChatUsers::present($users[$participant->user_id]),
                'last_read_message_id' => $participant->last_read_message_id,
            ])
            ->sortBy(fn (array $participant): string => mb_strtolower($participant['name']))
            ->values()
            ->all();

        return [
            'id' => $conversation->id,
            'type' => $conversation->type->value,
            'title' => self::title($conversation, $project, $other),
            'subtitle' => $project?->code,
            'icon' => self::icon($conversation, $client),
            'project' => $project === null ? null : self::project($project),
            'client' => $client === null ? null : self::client($client),
            'other_user' => $other === null ? null : ChatUsers::present($other),
            'participants' => $list,
            'muted' => $can->participant !== null && $can->participant->muted,
            'is_participant' => $can->participant !== null,
            'last_read_message_id' => $can->participant?->last_read_message_id,
            'can' => [
                'post' => $can->post,
                'moderate' => $can->moderate,
                'create_task' => $can->createTask,
                'mute' => $can->participant !== null,
                'manage' => $can->manage,
                'leave' => $can->leave,
                'join' => $can->join,
            ],
            'archived' => $conversation->archived_at !== null,
            'read_only_reason' => $can->readOnlyReason($conversation),
        ];
    }

    public static function title(Conversation $conversation, ?Project $project, ?User $other): string
    {
        return match ($conversation->type) {
            ConversationType::Project => $project !== null ? $project->name : __('conversations.untitled'),
            ConversationType::Direct => $other !== null ? $other->name : __('conversations.untitled'),
            ConversationType::Group, ConversationType::Team => $conversation->name ?? __('conversations.untitled'),
            ConversationType::Client => $conversation->client->name ?? __('conversations.untitled'),
        };
    }

    /**
     * Cliente al que pertenece la conversación: el del canal de cliente o el del proyecto.
     */
    public static function clientOf(Conversation $conversation, ?Project $project): ?Client
    {
        return match ($conversation->type) {
            ConversationType::Client => $conversation->client,
            ConversationType::Project => $project?->client,
            default => null,
        };
    }

    /**
     * Emoji de la conversación: el del canal de equipo o el del cliente en su canal.
     */
    public static function icon(Conversation $conversation, ?Client $client): ?string
    {
        return match ($conversation->type) {
            ConversationType::Team => $conversation->icon,
            ConversationType::Client => $client?->icon,
            default => null,
        };
    }

    /**
     * @return array{id: int, name: string, icon: string|null, is_active: bool}
     */
    public static function client(Client $client): array
    {
        return [
            'id' => $client->id,
            'name' => $client->name,
            'icon' => $client->icon,
            'is_active' => $client->is_active && ! $client->trashed(),
        ];
    }

    /**
     * @return array{id: int, code: string, name: string, color: string, status: string}
     */
    public static function project(Project $project): array
    {
        return [
            'id' => $project->id,
            'code' => $project->code,
            'name' => $project->name,
            'color' => $project->color,
            'status' => $project->status->value,
        ];
    }
}
