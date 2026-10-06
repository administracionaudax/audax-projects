<?php

namespace App\Domain\Chat;

use App\Enums\ConversationType;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Conversaciones del chat (SPEC §12, D-068):
 * - de proyecto: una por proyecto, creada al primer uso, con sus miembros como participantes;
 *   quien sale del proyecto deja de participar (left_at) pero su histórico se conserva,
 * - directas: una por pareja de personas internas activas,
 * - de grupo: con nombre, creadas por cualquier interno; las gestiona (renombrar, añadir y quitar
 *   personas) quien la creó o el admin, y cualquiera puede salir. Cada cambio deja un mensaje de
 *   sistema en el grupo (D-119) y una entrada en la auditoría (activity('chat'), D-074),
 * - canal de cliente (D-271): uno por cliente, creado al primer uso; participan los miembros de sus
 *   proyectos activos y lo ve toda la plantilla (ChannelMembership, ConversationAccess),
 * - canal de equipo (D-272): con nombre y emoji; lo crean, renombran y archivan los admins y
 *   participa toda la plantilla interna. De los canales se entra y se sale libremente.
 */
final class ConversationDirectory
{
    public function __construct(
        private readonly MessageWriter $writer,
        private readonly ChannelMembership $channels,
    ) {}

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
        $conversation->forgetParticipants();

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

        // Los miembros de un proyecto activo participan en el canal de su cliente (D-271).
        if ($project->client_id !== null && $project->acceptsTime()) {
            $channel = Conversation::query()->where('client_id', $project->client_id)->first();
            if ($channel !== null) {
                $this->channels->addNew($channel, array_values(array_map('intval', $memberIds)));
            }
        }
    }

    /**
     * Canal del cliente (D-271): uno por cliente, creado al primer uso con los miembros de sus
     * proyectos activos como participantes.
     */
    public function forClient(Client $client, ?int $createdBy = null): Conversation
    {
        $conversation = Conversation::query()->firstOrCreate(
            ['client_id' => $client->id],
            ['type' => ConversationType::Client, 'created_by' => $createdBy ?? $client->owner_user_id],
        );

        if ($conversation->wasRecentlyCreated) {
            $this->channels->syncChannel($conversation);
        }

        return $conversation;
    }

    /**
     * Canal de equipo nuevo (D-272), con toda la plantilla interna como participante.
     */
    public function createTeam(User $admin, string $name, ?string $icon): Conversation
    {
        Gate::forUser($admin)->authorize('create', Conversation::class);

        $name = mb_substr(trim($name), 0, 120);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => __('chat.errors.channel_name')]);
        }

        return DB::transaction(function () use ($admin, $name, $icon): Conversation {
            $conversation = Conversation::query()->create([
                'type' => ConversationType::Team,
                'name' => $name,
                'icon' => self::icon($icon),
                'created_by' => $admin->id,
            ]);

            $this->channels->syncChannel($conversation);
            $this->audit($admin, $conversation, 'channel_created', [
                'attributes' => ['name' => $conversation->name, 'icon' => $conversation->icon],
            ]);

            return $conversation;
        });
    }

    /**
     * Cambia el nombre o el emoji de un canal de equipo, o lo archiva (solo lectura) y lo
     * recupera (D-272). Cada cambio deja un mensaje de sistema y una entrada en la auditoría.
     */
    public function updateTeam(User $admin, Conversation $team, string $name, ?string $icon, bool $archived): void
    {
        Gate::forUser($admin)->authorize('manage', $team);

        $name = mb_substr(trim($name), 0, 120);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => __('chat.errors.channel_name')]);
        }

        $icon = self::icon($icon);
        $old = ['name' => $team->name, 'icon' => $team->icon, 'archived' => $team->archived_at !== null];

        if ($old['name'] !== $name || $old['icon'] !== $icon) {
            $team->forceFill(['name' => $name, 'icon' => $icon])->save();
            $this->writer->system($team, 'channel.renamed', ['by' => $admin->name, 'name' => $name]);
        }

        if ($old['archived'] !== $archived) {
            // El aviso va antes de archivarlo (después ya no se escribe en él).
            if ($archived) {
                $this->writer->system($team, 'channel.archived', ['by' => $admin->name]);
            }
            $team->forceFill(['archived_at' => $archived ? now() : null])->save();
            if (! $archived) {
                $this->writer->system($team, 'channel.unarchived', ['by' => $admin->name]);
                $this->channels->syncChannel($team);
            }
        }

        $new = ['name' => $team->name, 'icon' => $team->icon, 'archived' => $team->archived_at !== null];
        if ($new !== $old) {
            $this->audit($admin, $team, 'channel_updated', ['old' => $old, 'attributes' => $new]);
        }
    }

    /**
     * Añade personas a un canal de equipo (D-272): sobre todo colaboradores externos, que no
     * participan por defecto; la plantilla ya está.
     *
     * @param  list<int>  $userIds
     * @return list<int> las que se han añadido
     */
    public function addToTeam(User $admin, Conversation $team, array $userIds): array
    {
        Gate::forUser($admin)->authorize('manage', $team);

        $current = $team->activeParticipants()->pluck('user_id')->map(fn (mixed $id): int => (int) $id)->all();
        $people = User::query()->whereKey($userIds)->whereKeyNot($current)->active()->internal()->orderBy('name')->get(['id', 'name']);

        if ($people->isEmpty()) {
            throw ValidationException::withMessages(['user_ids' => __('chat.errors.group_add')]);
        }

        DB::transaction(function () use ($team, $people): void {
            foreach ($people as $person) {
                $this->join($team, $person->id);
            }
        });

        $this->audit($admin, $team, 'channel_members_added', [
            'attributes' => ['participants' => array_values($people->pluck('name')->all())],
        ]);

        return array_values($people->modelKeys());
    }

    /**
     * Quita a una persona de un canal de equipo (D-272). Con la plantilla solo tiene sentido para
     * los colaboradores externos: la plantilla puede volver a entrar cuando quiera.
     */
    public function removeFromTeam(User $admin, Conversation $team, User $member): void
    {
        Gate::forUser($admin)->authorize('manage', $team);

        if (! $team->hasParticipant($member)) {
            throw ValidationException::withMessages(['user' => __('chat.errors.group_remove')]);
        }

        $this->leave($team, $member->id);
        $this->audit($admin, $team, 'channel_member_removed', ['old' => ['participants' => [$member->name]]]);
    }

    /**
     * Entrar en un canal que se ve (D-270): desde ahí cuenta para no leídos y avisos.
     */
    public function joinChannel(User $user, Conversation $channel): ConversationParticipant
    {
        Gate::forUser($user)->authorize('join', $channel);

        return $this->join($channel, $user->id);
    }

    /**
     * Salir de un canal (D-270): se sigue viendo, pero sin no leídos ni avisos. No vuelve a entrar
     * solo (ChannelMembership solo añade a quien nunca ha estado).
     */
    public function leaveChannel(User $user, Conversation $channel): void
    {
        if (! $channel->type->isChannel()) {
            throw new AuthorizationException;
        }
        Gate::forUser($user)->authorize('leave', $channel);

        $this->leave($channel, $user->id);
    }

    /**
     * Emoji del canal: uno del selector (D-117) o ninguno.
     */
    private static function icon(?string $icon): ?string
    {
        $icon = trim((string) $icon);
        if ($icon === '') {
            return null;
        }

        if (mb_strlen($icon) > 16 || ! EmojiCatalog::contains($icon)) {
            throw ValidationException::withMessages(['icon' => __('chat.errors.emoji')]);
        }

        return $icon;
    }

    public function join(Conversation $conversation, int $userId): ConversationParticipant
    {
        return Participants::join($conversation, $userId);
    }

    public function leave(Conversation $conversation, int $userId): void
    {
        $conversation->forgetParticipants();

        ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->update(['left_at' => now()]);
    }

    public function direct(User $from, User $to): Conversation
    {
        // Un colaborador externo no tiene directas (D-134): ni las abre ni se le abren.
        if ($from->id === $to->id || ! $to->is_active || ! $to->isInternal() || ! $from->isInternal() || $from->isCollaborator() || $to->isCollaborator()) {
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
        // Un colaborador externo no está en grupos (D-134): ni los crea ni se le añade.
        $ids = User::query()->whereKey(array_unique([...$userIds, $creator->id]))
            ->where('is_active', true)->get()
            ->filter(fn (User $user): bool => $user->isInternal() && ! $user->isCollaborator())
            ->modelKeys();

        if ($name === '' || count($ids) < 2 || $creator->isCollaborator()) {
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

            $this->audit($creator, $conversation, 'group_created', [
                'attributes' => ['name' => $conversation->name, 'participants' => self::names($ids)],
            ]);

            return $conversation;
        });
    }

    public function renameGroup(User $actor, Conversation $group, string $name): void
    {
        Gate::forUser($actor)->authorize('manage', $group);

        $name = mb_substr(trim($name), 0, 120);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => __('chat.errors.group_name')]);
        }

        if ($name === $group->name) {
            return;
        }

        $previous = $group->name;
        $group->forceFill(['name' => $name])->save();
        $this->writer->system($group, 'group.renamed', ['by' => $actor->name, 'name' => $name]);
        $this->audit($actor, $group, 'group_renamed', ['old' => ['name' => $previous], 'attributes' => ['name' => $name]]);
    }

    /**
     * Añade personas internas y activas que aún no están en el grupo.
     *
     * @param  list<int>  $userIds
     * @return list<int> las que se han añadido
     */
    public function addToGroup(User $actor, Conversation $group, array $userIds): array
    {
        Gate::forUser($actor)->authorize('manage', $group);

        $current = $group->activeParticipants()->pluck('user_id')->map(fn (mixed $id): int => (int) $id)->all();
        $people = User::query()->whereKey($userIds)->whereKeyNot($current)->active()->internal()->withoutCollaborators()->orderBy('name')->get(['id', 'name']);

        if ($people->isEmpty()) {
            throw ValidationException::withMessages(['user_ids' => __('chat.errors.group_add')]);
        }

        DB::transaction(function () use ($group, $people): void {
            foreach ($people as $person) {
                $this->join($group, $person->id);
            }
        });

        $this->writer->system($group, 'group.added', [
            'by' => $actor->name,
            'users' => array_values($people->pluck('name')->all()),
        ]);
        $this->audit($actor, $group, 'group_members_added', [
            'attributes' => ['participants' => array_values($people->pluck('name')->all())],
        ]);

        return array_values($people->modelKeys());
    }

    public function removeFromGroup(User $actor, Conversation $group, User $member): void
    {
        Gate::forUser($actor)->authorize('manage', $group);

        if ($member->id === $actor->id || ! $group->hasParticipant($member)) {
            throw ValidationException::withMessages(['user' => __('chat.errors.group_remove')]);
        }

        $this->leave($group, $member->id);
        $this->writer->system($group, 'group.removed', ['by' => $actor->name, 'user' => $member->name]);
        $this->audit($actor, $group, 'group_member_removed', ['old' => ['participants' => [$member->name]]]);
    }

    public function leaveGroup(User $user, Conversation $group): void
    {
        if ($group->type !== ConversationType::Group) {
            throw new AuthorizationException;
        }
        Gate::forUser($user)->authorize('leave', $group);

        $this->leave($group, $user->id);
        $this->writer->system($group, 'group.left', ['user' => $user->name]);
        $this->audit($user, $group, 'group_left', ['old' => ['participants' => [$user->name]]]);
    }

    /**
     * Entrada de la auditoría de un cambio en un grupo (log «chat», como la moderación).
     *
     * @param  array<string, mixed>  $properties
     */
    private function audit(User $actor, Conversation $group, string $event, array $properties): void
    {
        activity('chat')->causedBy($actor)->performedOn($group)
            ->event($event)
            ->withProperties($properties)
            ->log("chat.{$event}");
    }

    /**
     * Nombres de las personas, por orden alfabético.
     *
     * @param  array<int, int|string>  $ids
     * @return list<string>
     */
    private static function names(array $ids): array
    {
        /** @var list<string> */
        return User::query()->whereKey($ids)->orderBy('name')->pluck('name')->all();
    }

    /**
     * Conversaciones que el admin puede moderar sin participar en ellas (D-071): las de proyecto y
     * las de grupo; las directas, nunca. Las más recientes primero.
     *
     * @return Builder<Conversation>
     */
    public function moderatable(User $admin): Builder
    {
        return Conversation::query()
            ->whereIn('type', [ConversationType::Project, ConversationType::Group])
            ->whereDoesntHave('participants', fn (Builder $query) => $query->where('user_id', $admin->id)->whereNull('left_at'))
            ->orderByRaw('coalesce(last_message_at, created_at) desc')
            ->orderByDesc('id');
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
     * @param  int|null  $upTo  solo hasta este mensaje (los contadores en vivo saben así qué
     *                          mensajes nuevos no estaban aún en el recuento)
     * @return array<int, int> id de la conversación => sin leer (solo las que tienen alguno)
     */
    public function unreadCounts(User $user, ?array $conversationIds = null, ?int $upTo = null): array
    {
        if ($conversationIds === []) {
            return [];
        }

        return $this->unread($user)
            ->when($conversationIds !== null, fn (QueryBuilder $query) => $query->whereIn('messages.conversation_id', $conversationIds))
            ->when($upTo !== null, fn (QueryBuilder $query) => $query->where('messages.id', '<=', $upTo))
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
        $projectIds = $user->visibleProjectIds();

        return DB::table('messages')
            ->join('conversation_participants as p', function (JoinClause $join) use ($user): void {
                $join->on('p.conversation_id', '=', 'messages.conversation_id')
                    ->where('p.user_id', '=', $user->id)
                    ->whereNull('p.left_at');
            })
            // Un colaborador externo solo cuenta las de su alcance (D-134, D-271).
            ->when($projectIds !== null, fn (QueryBuilder $query) => $query->whereIn('messages.conversation_id', Conversation::query()
                ->select('conversations.id')
                ->where(fn (Builder $scope) => ConversationAccess::scope($scope, $user))
                ->toBase()))
            ->whereRaw('messages.id > coalesce(p.last_read_message_id, 0)')
            ->where(fn (QueryBuilder $query) => $query->whereNull('messages.user_id')->orWhere('messages.user_id', '!=', $user->id))
            ->whereNull('messages.deleted_at')
            ->whereNull('messages.hidden_at');
    }

    /**
     * Conversaciones en las que participa (activas) y que puede ver, las más recientes primero
     * (un colaborador externo, solo las de su alcance, D-134).
     *
     * @return Builder<Conversation>
     */
    public function forUser(User $user): Builder
    {
        return Conversation::query()
            ->whereHas('participants', fn (Builder $query) => $query->where('user_id', $user->id)->whereNull('left_at'))
            ->where(fn (Builder $query) => ConversationAccess::scope($query, $user))
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');
    }

    /**
     * Lo que sale en la lista del chat (D-273): aquellas en las que participa y, además, todos los
     * canales que puede ver aunque no participe (para entrar en ellos sin pasar por otro sitio).
     *
     * @return Builder<Conversation>
     */
    public function listable(User $user): Builder
    {
        return Conversation::query()
            ->where(fn (Builder $query) => ConversationAccess::scope($query, $user))
            ->where(fn (Builder $query) => $query
                ->whereIn('conversations.type', ConversationType::channelValues())
                ->orWhereHas('participants', fn (Builder $participants) => $participants->where('user_id', $user->id)->whereNull('left_at')));
    }
}
