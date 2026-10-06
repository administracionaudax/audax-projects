<?php

namespace App\Http\Controllers\Chat;

use App\Domain\Chat\ConversationDirectory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\StoreChannelRequest;
use App\Http\Requests\Chat\UpdateChannelRequest;
use App\Http\Resources\Chat\ConversationAbilities;
use App\Http\Resources\Chat\ConversationPresenter;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Canales del chat (D-270 a D-272): crear y cambiar los de equipo (admins), entrar y salir de un
 * canal que se ve, y abrir el canal de un cliente (se crea la primera vez). Todo pasa por
 * ConversationDirectory, que comprueba ConversationPolicy.
 */
class ChannelController extends Controller
{
    public function __construct(private readonly ConversationDirectory $directory) {}

    public function store(StoreChannelRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $conversation = $this->directory->createTeam($user, $request->string('name')->toString(), $request->input('icon'));

        return response()->json(['id' => $conversation->id, 'url' => "/chat/{$conversation->id}"], 201);
    }

    public function update(UpdateChannelRequest $request, Conversation $conversation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->directory->updateTeam(
            $user,
            $conversation,
            $request->string('name')->toString(),
            $request->input('icon'),
            $request->has('archived') ? $request->boolean('archived') : $conversation->archived_at !== null,
        );

        return $this->conversation($conversation->refresh(), $user);
    }

    public function join(Request $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);

        /** @var User $user */
        $user = $request->user();
        $this->directory->joinChannel($user, $conversation);

        return $this->conversation($conversation, $user);
    }

    public function leave(Request $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);

        /** @var User $user */
        $user = $request->user();
        $this->directory->leaveChannel($user, $conversation);

        return $this->conversation($conversation, $user);
    }

    /**
     * El canal del cliente desde su ficha o desde la lista del chat: se crea la primera vez y se
     * abre. Lo ve quien ve los proyectos de ese cliente (D-271): la plantilla y los colaboradores
     * con proyectos suyos.
     */
    public function client(Request $request, Client $client): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $clients = $user->visibleClientIds();
        abort_unless($user->isInternal() && ($clients === null || in_array($client->id, $clients, true)), 404);

        $conversation = $this->directory->forClient($client, $user->id);
        Gate::authorize('view', $conversation);

        return redirect()->route('chat.show', $conversation);
    }

    private function conversation(Conversation $conversation, User $user): JsonResponse
    {
        return response()->json([
            'conversation' => ConversationPresenter::detail($conversation, $user, ConversationAbilities::for($user, $conversation)),
        ]);
    }
}
