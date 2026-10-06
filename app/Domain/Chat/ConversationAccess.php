<?php

namespace App\Domain\Chat;

use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Quién ve cada conversación (SPEC §12, D-071, D-134 y D-270 a D-272), en una sola regla para la
 * política (ConversationPolicy::view) y para las consultas (listas, búsqueda, contadores):
 *
 * - directa: sus participantes activos (nunca un colaborador externo),
 * - grupo y proyecto: sus participantes activos y el admin, que modera; un colaborador externo,
 *   solo la de un proyecto que ve y si participa,
 * - canal de cliente: toda la plantilla interna; un colaborador externo, si ve algún proyecto de
 *   ese cliente,
 * - canal de equipo: toda la plantilla interna; un colaborador externo, solo si participa (lo
 *   añadió un admin o lo era en ClickUp).
 *
 * Siempre personas internas y activas.
 */
final class ConversationAccess
{
    public static function canView(User $user, Conversation $conversation): bool
    {
        if (! $user->isInternal() || ! $user->is_active) {
            return false;
        }

        if ($user->isCollaborator()) {
            return match ($conversation->type) {
                ConversationType::Project => $conversation->project_id !== null
                    && $user->canSeeProject($conversation->project_id)
                    && $conversation->hasParticipant($user),
                ConversationType::Client => $conversation->client_id !== null
                    && in_array($conversation->client_id, $user->visibleClientIds() ?? [], true),
                ConversationType::Team => $conversation->hasParticipant($user),
                ConversationType::Direct, ConversationType::Group => false,
            };
        }

        return match ($conversation->type) {
            ConversationType::Client, ConversationType::Team => true,
            ConversationType::Direct => $conversation->hasParticipant($user),
            ConversationType::Project, ConversationType::Group => $user->hasRole('admin') || $conversation->hasParticipant($user),
        };
    }

    /**
     * La misma regla sobre una consulta de conversaciones, o de mensajes con la tabla conversations
     * unida (columnas siempre con el nombre de la tabla), sin una consulta por fila.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    public static function scope(Builder $query, User $user): void
    {
        if (! $user->isInternal() || ! $user->is_active) {
            $query->whereRaw('1 = 0');

            return;
        }

        $participating = ConversationParticipant::query()
            ->select('conversation_id')
            ->where('user_id', $user->id)
            ->whereNull('left_at');

        if ($user->isCollaborator()) {
            $projectIds = $user->visibleProjectIds() ?? [];
            $clientIds = $user->visibleClientIds() ?? [];

            $query->where(fn (Builder $visible) => $visible
                ->where(fn (Builder $project) => $project
                    ->where('conversations.type', ConversationType::Project->value)
                    ->whereIn('conversations.project_id', $projectIds)
                    ->whereIn('conversations.id', $participating))
                ->orWhere(fn (Builder $client) => $client
                    ->where('conversations.type', ConversationType::Client->value)
                    ->whereIn('conversations.client_id', $clientIds))
                ->orWhere(fn (Builder $team) => $team
                    ->where('conversations.type', ConversationType::Team->value)
                    ->whereIn('conversations.id', $participating)));

            return;
        }

        $admin = $user->hasRole('admin');

        $query->where(function (Builder $visible) use ($participating, $admin): void {
            $visible->whereIn('conversations.type', ConversationType::channelValues())
                ->orWhereIn('conversations.id', $participating);

            if ($admin) {
                $visible->orWhereIn('conversations.type', [ConversationType::Project->value, ConversationType::Group->value]);
            }
        });
    }
}
