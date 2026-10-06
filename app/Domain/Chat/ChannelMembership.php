<?php

namespace App\Domain\Chat;

use App\Enums\ConversationType;
use App\Enums\ProjectStatus;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Quién participa en los canales (D-270 a D-272): participar es lo que da no leídos, avisos de
 *
 * @todos y tiempo real; verlos lo decide ConversationAccess.
 *
 * - Canal de equipo: toda la plantilla interna activa (sin colaboradores externos, que solo entran
 *   si un admin los añade o lo eran en ClickUp).
 * - Canal de cliente: los miembros de los proyectos activos de ese cliente. El resto de la
 *   plantilla lo ve y puede entrar («Unirme»).
 *
 * Solo se añade a quien NUNCA ha tenido fila: quien sale de un canal no vuelve a entrar solo. Lo
 * anterior a su entrada cuenta como leído (como al entrar en un grupo, D-119). Se hace al crear el
 * canal, al entrar alguien en un proyecto del cliente y, como red de seguridad, al pedir la lista
 * del chat (ensureFor, dos consultas como mucho).
 */
final class ChannelMembership
{
    /**
     * Añade a quien mira a los canales que le tocan y en los que nunca ha estado.
     */
    public function ensureFor(User $user): void
    {
        if (! $user->isInternal() || ! $user->is_active) {
            return;
        }

        $never = fn (QueryBuilder $query) => $query->select(DB::raw(1))
            ->from('conversation_participants as p')
            ->whereColumn('p.conversation_id', 'conversations.id')
            ->where('p.user_id', $user->id);

        $missing = DB::table('conversations')
            ->whereNotExists($never)
            ->where(function (QueryBuilder $query) use ($user): void {
                if (! $user->isCollaborator()) {
                    $query->where(fn (QueryBuilder $team) => $team
                        ->where('conversations.type', ConversationType::Team->value)
                        ->whereNull('conversations.archived_at'));
                }

                $query->orWhere(fn (QueryBuilder $client) => $client
                    ->where('conversations.type', ConversationType::Client->value)
                    ->whereIn('conversations.client_id', DB::table('projects')
                        ->join('project_members', 'project_members.project_id', '=', 'projects.id')
                        ->select('projects.client_id')
                        ->where('project_members.user_id', $user->id)
                        ->where('projects.status', '!=', ProjectStatus::Archived->value)
                        ->whereNull('projects.deleted_at')
                        ->whereNotNull('projects.client_id')));
            })
            ->select('conversations.id')
            ->selectSub(fn (QueryBuilder $last) => $last->from('messages')
                ->selectRaw('max(id)')
                ->whereColumn('messages.conversation_id', 'conversations.id'), 'last_id')
            ->get();

        $this->insert(array_values($missing->map(fn (object $row): array => [
            'conversation_id' => (int) $row->id,
            'user_id' => $user->id,
            'last_id' => $row->last_id === null ? null : (int) $row->last_id,
        ])->all()));
    }

    /**
     * Pone al día un canal: añade a quien le toca y nunca ha estado en él.
     */
    public function syncChannel(Conversation $conversation): void
    {
        $candidates = match ($conversation->type) {
            ConversationType::Team => $conversation->archived_at !== null ? [] : User::query()
                ->active()->internal()->withoutCollaborators()->pluck('id')->all(),
            ConversationType::Client => $conversation->client_id === null ? [] : DB::table('project_members')
                ->join('projects', 'projects.id', '=', 'project_members.project_id')
                ->join('users', 'users.id', '=', 'project_members.user_id')
                ->where('projects.client_id', $conversation->client_id)
                ->where('projects.status', '!=', ProjectStatus::Archived->value)
                ->whereNull('projects.deleted_at')
                ->where('users.is_active', true)
                ->distinct()
                ->pluck('project_members.user_id')
                ->all(),
            default => [],
        };

        $this->addNew($conversation, array_values(array_map('intval', $candidates)));
    }

    /**
     * Añade a estas personas si nunca han estado en el canal (sin mensajes de sistema).
     *
     * @param  list<int>  $userIds
     */
    public function addNew(Conversation $conversation, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        $existing = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->whereIn('user_id', $userIds)
            ->pluck('user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $new = array_values(array_diff(array_unique($userIds), $existing));

        if ($new === []) {
            return;
        }

        $conversation->forgetParticipants();
        $last = $conversation->messages()->withTrashed()->max('id');
        $this->insert(array_map(fn (int $id): array => [
            'conversation_id' => $conversation->id,
            'user_id' => $id,
            'last_id' => $last === null ? null : (int) $last,
        ], $new));
    }

    /**
     * @param  list<array{conversation_id: int, user_id: int, last_id: int|null}>  $rows
     */
    private function insert(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $now = now();

        foreach (array_chunk($rows, 500) as $chunk) {
            ConversationParticipant::query()->insertOrIgnore(array_map(fn (array $row): array => [
                'conversation_id' => $row['conversation_id'],
                'user_id' => $row['user_id'],
                'last_read_message_id' => $row['last_id'],
                'muted' => false,
                'joined_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }
    }
}
