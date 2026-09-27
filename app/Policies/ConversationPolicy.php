<?php

namespace App\Policies;

use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\User;

/**
 * Conversaciones (SPEC §12, D-071):
 * - ven y escriben sus participantes activos (internos y activos),
 * - el admin ve y modera las de proyecto y de grupo aunque no participe; las directas, nunca,
 * - en el chat de un proyecto archivado ya no se escribe: se conserva para consultarlo.
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
}
