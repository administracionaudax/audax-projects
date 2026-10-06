<?php

namespace App\Http\Controllers\Chat;

use App\Domain\Chat\ConversationDirectory;
use App\Enums\ConversationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\AddGroupMembersRequest;
use App\Http\Requests\Chat\UpdateGroupRequest;
use App\Http\Resources\Chat\ConversationAbilities;
use App\Http\Resources\Chat\ConversationPresenter;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Gestión básica de los grupos (D-119): renombrar y añadir o quitar personas (quien lo creó o el
 * admin) y salir del grupo (cualquiera de sus participantes). Todo pasa por ConversationDirectory,
 * que comprueba ConversationPolicy::manage o ::leave y deja un mensaje de sistema en el grupo.
 * Responden la cabecera actualizada de la conversación (ChatConversation).
 */
class GroupController extends Controller
{
    public function __construct(private readonly ConversationDirectory $directory) {}

    public function update(UpdateGroupRequest $request, Conversation $conversation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->directory->renameGroup($user, $conversation, $request->string('name')->toString());

        return $this->conversation($conversation, $user);
    }

    public function addMembers(AddGroupMembersRequest $request, Conversation $conversation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var list<int> $ids */
        $ids = array_values(array_map('intval', (array) $request->input('user_ids', [])));

        // Canal de equipo (D-272): el admin añade personas (sobre todo colaboradores externos).
        $conversation->type === ConversationType::Team
            ? $this->directory->addToTeam($user, $conversation, $ids)
            : $this->directory->addToGroup($user, $conversation, $ids);

        return $this->conversation($conversation, $user);
    }

    public function removeMember(Request $request, Conversation $conversation, User $member): JsonResponse
    {
        Gate::authorize('view', $conversation);

        /** @var User $user */
        $user = $request->user();

        $conversation->type === ConversationType::Team
            ? $this->directory->removeFromTeam($user, $conversation, $member)
            : $this->directory->removeFromGroup($user, $conversation, $member);

        return $this->conversation($conversation, $user);
    }

    public function leave(Request $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);

        /** @var User $user */
        $user = $request->user();

        $this->directory->leaveGroup($user, $conversation);

        return response()->json(['left' => true, 'url' => route('chat.index', absolute: false)]);
    }

    private function conversation(Conversation $conversation, User $user): JsonResponse
    {
        $conversation->refresh();

        return response()->json([
            'conversation' => ConversationPresenter::detail($conversation, $user, ConversationAbilities::for($user, $conversation)),
        ]);
    }
}
