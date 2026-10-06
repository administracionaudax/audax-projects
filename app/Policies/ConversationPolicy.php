<?php

namespace App\Policies;

use App\Domain\Chat\ConversationAccess;
use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\User;

/**
 * Conversaciones (SPEC §12, D-071, D-134 y D-270 a D-272). Quién ve cada una lo dice
 * ConversationAccess (una sola regla para la política y las consultas). Además:
 * - escriben sus participantes activos; en los canales (de cliente y de equipo), cualquiera que
 *   los vea, y al escribir pasa a participar,
 * - en el chat de un proyecto archivado, el canal de un cliente desactivado o un canal de equipo
 *   archivado ya no se escribe: se conservan para consultarlos,
 * - el admin modera todas salvo las directas,
 * - un grupo lo gestiona (nombre y personas) quien lo creó, mientras siga en él, o el admin; y
 *   cualquiera de sus participantes puede salir de él (D-119); un canal de equipo lo gestionan
 *   los admins (D-272),
 * - de un canal se entra y se sale libremente (sin mensajes de sistema).
 */
class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return ConversationAccess::canView($user, $conversation);
    }

    public function post(User $user, Conversation $conversation): bool
    {
        if (! $this->view($user, $conversation)) {
            return false;
        }

        return match ($conversation->type) {
            ConversationType::Project => $conversation->hasParticipant($user)
                && $conversation->project !== null && $conversation->project->acceptsTime(),
            ConversationType::Direct, ConversationType::Group => $conversation->hasParticipant($user),
            ConversationType::Client => $conversation->client !== null
                && $conversation->client->is_active && ! $conversation->client->trashed(),
            ConversationType::Team => $conversation->archived_at === null,
        };
    }

    /**
     * Crear un canal de equipo (D-272): solo los admins. (Directas y grupos tienen su propia regla
     * en ConversationDirectory.)
     */
    public function create(User $user): bool
    {
        return $user->isInternal() && $user->is_active && $user->hasRole('admin');
    }

    public function moderate(User $user, Conversation $conversation): bool
    {
        return $user->hasRole('admin') && $conversation->type !== ConversationType::Direct;
    }

    public function manage(User $user, Conversation $conversation): bool
    {
        if (! $user->isInternal() || ! $user->is_active) {
            return false;
        }

        return match ($conversation->type) {
            ConversationType::Group => $user->hasRole('admin')
                || ($conversation->created_by === $user->id && $conversation->hasParticipant($user)),
            ConversationType::Team => $user->hasRole('admin'),
            default => false,
        };
    }

    public function leave(User $user, Conversation $conversation): bool
    {
        return ($conversation->type === ConversationType::Group || $conversation->type->isChannel())
            && $conversation->hasParticipant($user);
    }

    /**
     * Entrar en un canal que se ve sin participar (para sus avisos y no leídos).
     */
    public function join(User $user, Conversation $conversation): bool
    {
        return $conversation->type->isChannel()
            && $this->view($user, $conversation)
            && ! $conversation->hasParticipant($user);
    }
}
