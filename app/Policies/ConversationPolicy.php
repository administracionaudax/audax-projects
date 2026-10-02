<?php

namespace App\Policies;

use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\User;

/**
 * Conversaciones (SPEC §12, D-071):
 * - ven y escriben sus participantes activos (internos y activos),
 * - el admin ve y modera las de proyecto y de grupo aunque no participe; las directas, nunca,
 * - en el chat de un proyecto archivado ya no se escribe: se conserva para consultarlo,
 * - un grupo lo gestiona (nombre y personas) quien lo creó, mientras siga en él, o el admin; y
 *   cualquiera de sus participantes puede salir de él (D-119).
 */
class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        if (! $user->isInternal() || ! $user->is_active) {
            return false;
        }

        return $conversation->hasParticipant($user)
            || ($user->hasRole('admin') && $conversation->type !== ConversationType::Direct);
    }

    public function post(User $user, Conversation $conversation): bool
    {
        if (! $user->isInternal() || ! $user->is_active || ! $conversation->hasParticipant($user)) {
            return false;
        }

        return $conversation->type !== ConversationType::Project
            || ($conversation->project !== null && $conversation->project->acceptsTime());
    }

    public function moderate(User $user, Conversation $conversation): bool
    {
        return $user->hasRole('admin') && $conversation->type !== ConversationType::Direct;
    }

    public function manage(User $user, Conversation $conversation): bool
    {
        if ($conversation->type !== ConversationType::Group || ! $user->isInternal() || ! $user->is_active) {
            return false;
        }

        return $user->hasRole('admin')
            || ($conversation->created_by === $user->id && $conversation->hasParticipant($user));
    }

    public function leave(User $user, Conversation $conversation): bool
    {
        return $conversation->type === ConversationType::Group && $conversation->hasParticipant($user);
    }
}
