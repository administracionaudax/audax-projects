<?php

namespace App\Http\Resources\Chat;

use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Lo que quien mira puede hacer en una conversación, calculado UNA vez por petición con las
 * políticas (ConversationPolicy, TaskPolicy; D-071). Los permisos de cada mensaje salen de aquí
 * sin volver a consultar (MessagePresenter); las acciones los vuelven a comprobar en el servidor
 * con MessagePolicy a través de MessageWriter.
 */
final readonly class ConversationAbilities
{
    public function __construct(
        public bool $view,
        public bool $post,
        public bool $moderate,
        public bool $createTask,
        public ?ConversationParticipant $participant,
        public bool $manage = false,
        public bool $leave = false,
        public bool $join = false,
    ) {}

    public static function for(User $user, Conversation $conversation): self
    {
        $participant = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->first();

        $gate = Gate::forUser($user);
        $project = $conversation->type === ConversationType::Project ? $conversation->project : null;
        $group = $conversation->type === ConversationType::Group;
        $channel = $conversation->type->isChannel();
        $view = $gate->allows('view', $conversation);

        return new self(
            view: $view,
            post: $gate->allows('post', $conversation),
            moderate: $gate->allows('moderate', $conversation),
            createTask: $project !== null && $gate->allows('create', [Task::class, $project]),
            participant: $participant,
            // Grupos (D-119): lo gestiona quien lo creó (si sigue en él) o el admin; sale quien
            // participa. Canales de equipo (D-272): los gestionan los admins. De los canales se
            // entra y se sale libremente (D-270).
            manage: ($group || $conversation->type === ConversationType::Team) && $gate->allows('manage', $conversation),
            leave: ($group || $channel) && $participant !== null,
            join: $channel && $view && $participant === null,
        );
    }

    /**
     * Por qué no puede escribir, para explicarlo en lugar del editor (o null si puede).
     *
     * @return 'archived'|'not_participant'|'inactive'|'client_inactive'|null
     */
    public function readOnlyReason(Conversation $conversation): ?string
    {
        if ($this->post) {
            return null;
        }

        if ($this->participant === null && ! $conversation->type->isChannel()) {
            return 'not_participant';
        }

        return match ($conversation->type) {
            ConversationType::Project, ConversationType::Team => 'archived',
            ConversationType::Client => 'client_inactive',
            default => 'inactive',
        };
    }
}
