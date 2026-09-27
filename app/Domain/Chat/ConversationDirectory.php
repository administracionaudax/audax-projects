<?php

namespace App\Domain\Chat;

use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Conversaciones del chat (SPEC §12, D-068):
 * - de proyecto: una por proyecto, creada al primer uso, con sus miembros como participantes;
 *   quien sale del proyecto deja de participar (left_at) pero su histórico se conserva,
 * - directas: una por pareja de personas internas activas,
 * - de grupo: con nombre, creadas por cualquier interno.
 */
final class ConversationDirectory
{
    public function forProject(Project $project): Conversation
    {
        $conversation = Conversation::query()->firstOrCreate(
            ['project_id' => $project->id],
            ['type' => ConversationType::Project, 'created_by' => $project->owner_user_id],
        );

        if ($conversation->wasRecentlyCreated) {
            $this->syncProject($project, $conversation);
        }

        return $conversation;
    }

    /**
     * Participantes activos = miembros del proyecto (entran los nuevos; salen, con left_at, los que ya no están).
     */
    public function syncProject(Project $project, ?Conversation $conversation = null): void
    {
        $conversation ??= Conversation::query()->where('project_id', $project->id)->first();
        if ($conversation === null) {
            return;
        }

        $memberIds = $project->members()->pluck('users.id')->all();

        DB::transaction(function () use ($conversation, $memberIds): void {
            foreach ($memberIds as $userId) {
                $this->join($conversation, (int) $userId);
            }

            ConversationParticipant::query()
                ->where('conversation_id', $conversation->id)
                ->whereNotIn('user_id', $memberIds)
                ->whereNull('left_at')
                ->update(['left_at' => now()]);
        });
    }

    public function join(Conversation $conversation, int $userId): ConversationParticipant
    {
        $participant = ConversationParticipant::query()->firstOrNew([
            'conversation_id' => $conversation->id,
            'user_id' => $userId,
        ]);

        if (! $participant->exists || $participant->left_at !== null) {
            $participant->fill(['joined_at' => $participant->exists ? $participant->joined_at : now(), 'left_at' => null]);
            // Al volver, lo anterior cuenta como leído: solo avisa de lo nuevo.
            $participant->last_read_message_id ??= $conversation->messages()->max('id');
            $participant->save();
        }

        return $participant;
    }

    public function leave(Conversation $conversation, int $userId): void
    {
        ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->update(['left_at' => now()]);
    }

    public function direct(User $from, User $to): Conversation
    {
        if ($from->id === $to->id || ! $to->is_active || ! $to->isInternal() || ! $from->isInternal()) {
            throw ValidationException::withMessages(['user_id' => __('chat.errors.direct_invalid')]);
        }

        return DB::transaction(function () use ($from, $to): Conversation {
            $conversation = Conversation::query()->firstOrCreate(
                ['direct_key' => Conversation::directKey($from->id, $to->id)],
                ['type' => ConversationType::Direct, 'created_by' => $from->id],
            );

            $this->join($conversation, $from->id);
            $this->join($conversation, $to->id);

            return $conversation;
        });
    }

    /**
     * @param  list<int>  $userIds  además de quien la crea
     */
    public function group(User $creator, string $name, array $userIds): Conversation
    {
        $name = trim($name);
        $ids = User::query()->whereKey(array_unique([...$userIds, $creator->id]))
            ->where('is_active', true)->get()
            ->filter(fn (User $user): bool => $user->isInternal())
            ->modelKeys();

        if ($name === '' || count($ids) < 2) {
            throw ValidationException::withMessages(['name' => __('chat.errors.group_invalid')]);
        }

        return DB::transaction(function () use ($creator, $name, $ids): Conversation {
            $conversation = Conversation::query()->create([
                'type' => ConversationType::Group,
                'name' => mb_substr($name, 0, 120),
                'created_by' => $creator->id,
            ]);

            foreach ($ids as $id) {
                $this->join($conversation, (int) $id);
            }

            return $conversation;
        });
    }

    /**
     * Conversaciones en las que participa (activas), las más recientes primero.
     *
     * @return Builder<Conversation>
     */
    public function forUser(User $user): Builder
    {
        return Conversation::query()
            ->whereHas('participants', fn (Builder $query) => $query->where('user_id', $user->id)->whereNull('left_at'))
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');
    }
}
