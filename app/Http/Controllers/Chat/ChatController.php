<?php

namespace App\Http\Controllers\Chat;

use App\Domain\Chat\ConversationDirectory;
use App\Http\Controllers\Controller;
use App\Http\Resources\Chat\ChatUsers;
use App\Http\Resources\Chat\ConversationList;
use App\Http\Resources\Chat\ConversationView;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /chat (SPEC §12): la lista de conversaciones de quien mira y, en /chat/{conversación}, la
 * conversación abierta (en escritorio, lista y conversación a la vez; en el móvil, pantallas
 * separadas). ?mensaje={id} abre la conversación en ese mensaje (enlaces, citas, búsqueda).
 * La lista y el total de no leídos también se sirven en JSON para la consulta periódica.
 */
class ChatController extends Controller
{
    use RespondsWithMessages;

    public function __construct(
        private readonly ConversationList $list,
        private readonly ConversationDirectory $directory,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('chat/index', [
            'conversations' => fn (): array => $this->list->for($user),
            'conversation' => null,
            'messages' => null,
            'pinned' => [],
            'focus' => null,
        ]);
    }

    public function show(Request $request, Conversation $conversation, ConversationView $view): Response
    {
        Gate::authorize('view', $conversation);

        /** @var User $user */
        $user = $request->user();
        $props = $view->props($conversation, $user, $this->positiveInt($request->query('mensaje')));

        return Inertia::render('chat/index', [
            'conversations' => fn (): array => $this->list->for($user),
            ...$props,
        ]);
    }

    /**
     * La lista en JSON (consulta periódica sin tiempo real) con el total de la navegación.
     */
    public function conversations(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'conversations' => $this->list->for($user),
            'unread_total' => $this->directory->unreadTotal($user),
        ]);
    }

    /**
     * Personas con las que se puede abrir una directa o crear un grupo: internas y activas.
     */
    public function people(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $people = User::query()
            ->active()
            ->internal()
            ->whereKeyNot($user->id)
            ->with(['department' => fn (Relation $query) => $query->select(['id', 'name'])])
            ->orderBy('name')
            ->get(['id', 'name', 'avatar_path', 'is_active', 'department_id']);

        return response()->json([
            'people' => array_values($people->map(fn (User $person): array => [
                ...ChatUsers::present($person),
                'department' => $person->department?->name,
            ])->all()),
        ]);
    }
}
