<?php

namespace App\Domain\Chat;

use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
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
            // Al entrar o al volver, lo anterior (también lo publicado mientras no estaba) cuenta
            // como leído: solo avisa de lo nuevo. Nunca hacia atrás.
            $last = $conversation->messages()->withTrashed()->max('id');
            if ($last !== null && (int) $last > (int) ($participant->last_read_message_id ?? 0)) {
                $participant->last_read_message_id = (int) $last;
            }
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
     * Silencia o reactiva una conversación para quien participa en ella (SPEC §12). Una silenciada
     * no suma en el total de no leídos de la navegación ni avisa por Web Push (D-072).
     *
     * @throws AuthorizationException si no participa (p. ej. el admin que modera un chat ajeno)
     */
    public function mute(User $user, Conversation $conversation, bool $muted): ConversationParticipant
    {
        $participant = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->first();

        if ($participant === null) {
            throw new AuthorizationException(__('conversations.errors.not_participant'));
        }

        if ($participant->muted !== $muted) {
            $participant->forceFill(['muted' => $muted])->save();
        }

        return $participant;
    }

    /**
     * Mensajes sin leer por conversación, en una sola consulta agregada: de otras personas o de
     * sistema, posteriores a lo leído y ni borrados ni ocultados, en las que participa.
     *
     * @param  list<int>|null  $conversationIds  null = todas
     * @return array<int, int> id de la conversación => sin leer (solo las que tienen alguno)
     */
    public function unreadCounts(User $user, ?array $conversationIds = null): array
    {
        if ($conversationIds === []) {
            return [];
        }

        return $this->unread($user)
            ->when($conversationIds !== null, fn (QueryBuilder $query) => $query->whereIn('messages.conversation_id', $conversationIds))
            ->groupBy('messages.conversation_id')
            ->select('messages.conversation_id')
            ->selectRaw('count(*) as unread')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->conversation_id => (int) $row->unread])
            ->all();
    }

    /**
     * Total sin leer de la navegación: el de las conversaciones no silenciadas (una consulta).
     */
    public function unreadTotal(User $user): int
    {
        return $this->unread($user)->where('p.muted', false)->count();
    }

    private function unread(User $user): QueryBuilder
    {
        return DB::table('messages')
            ->join('conversation_participants as p', function (JoinClause $join) use ($user): void {
                $join->on('p.conversation_id', '=', 'messages.conversation_id')
                    ->where('p.user_id', '=', $user->id)
                    ->whereNull('p.left_at');
            })
            ->whereRaw('messages.id > coalesce(p.last_read_message_id, 0)')
            ->where(fn (QueryBuilder $query) => $query->whereNull('messages.user_id')->orWhere('messages.user_id', '!=', $user->id))
            ->whereNull('messages.deleted_at')
            ->whereNull('messages.hidden_at');
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
