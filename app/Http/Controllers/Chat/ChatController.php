<?php

namespace App\Http\Controllers\Chat;

use App\Domain\Chat\ConversationDirectory;
use App\Http\Controllers\Controller;
use App\Http\Resources\Chat\ChatUsers;
use App\Http\Resources\Chat\ConversationList;
use App\Http\Resources\Chat\ConversationPresenter;
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
     * Conversaciones que el admin modera sin participar en ellas (D-071, D-119): las de proyecto y
     * las de grupo, nunca las directas. Solo lo necesario para abrirlas (la ruta exige el rol).
     */
    public function moderation(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $conversations = $this->directory->moderatable($user)
            ->with(['project' => fn (Relation $query) => $query->select(['id', 'code', 'name', 'color', 'status'])])
            ->withCount('activeParticipants')
            ->limit(ConversationList::MAX)
            ->get();

        return response()->json([
            'conversations' => array_values($conversations->map(fn (Conversation $conversation): array => [
                'id' => $conversation->id,
                'type' => $conversation->type->value,
                'title' => ConversationPresenter::title($conversation, $conversation->project, null),
                'subtitle' => $conversation->project?->code,
                'members_count' => (int) ($conversation->getAttribute('active_participants_count') ?? 0),
                'read_only' => $conversation->project !== null && ! $conversation->project->acceptsTime(),
                'last_activity_at' => ($conversation->last_message_at ?? $conversation->created_at)?->toIso8601ZuluString(),
            ])->all()),
        ]);
    }

    /**
     * Personas con las que se puede abrir una directa o crear un grupo: internas y activas, sin
     * colaboradores externos (D-134: no tienen directas ni grupos).
     */
    public function people(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $people = User::query()
            ->active()
            ->internal()
            ->withoutCollaborators()
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
