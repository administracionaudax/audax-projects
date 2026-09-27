<?php

namespace App\Http\Controllers\Chat;

use App\Domain\Chat\Links\LinkPreviews;
use App\Domain\Chat\MessageWriter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\StoreMessageRequest;
use App\Http\Requests\Chat\UpdateMessageRequest;
use App\Http\Resources\Chat\ConversationAbilities;
use App\Http\Resources\Chat\MessagePresenter;
use App\Http\Resources\Chat\MessageWindow;
use App\Http\Resources\Chat\PinnedPresenter;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Mensajes de una conversación (SPEC §12, D-069): páginas por cursor (hacia atrás, hacia delante
 * y alrededor de un mensaje), la consulta periódica sin tiempo real, publicar, editar y borrar.
 * Todo lo que se lee lo autoriza ConversationPolicy::view y todo lo que se escribe pasa por
 * MessageWriter, que comprueba su política y dispara los eventos del tiempo real.
 */
class MessageController extends Controller
{
    use RespondsWithMessages;

    public function __construct(
        private readonly MessageWriter $writer,
        private readonly MessageWindow $window,
    ) {}

    /**
     * ?antes={id} (scroll hacia arriba), ?despues={id} (hacia abajo tras saltar a un mensaje),
     * ?alrededor={id} (ir a un mensaje) o, sin nada, los últimos.
     */
    public function index(Request $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);

        /** @var User $user */
        $user = $request->user();
        $before = $this->positiveInt($request->query('antes'));
        $after = $this->positiveInt($request->query('despues'));
        $around = $this->positiveInt($request->query('alrededor'));

        if ($around !== null && ! $conversation->messages()->withTrashed()->whereKey($around)->exists()) {
            abort(404);
        }

        [$messages, $hasOlder, $hasNewer] = match (true) {
            $around !== null => $this->window->around($conversation, $around),
            $before !== null => $this->window->before($conversation, $before),
            $after !== null => $this->window->after($conversation, $after),
            default => $this->window->latest($conversation),
        };

        return response()->json([
            ...(new MessagePresenter($user, ConversationAbilities::for($user, $conversation)))->present($messages),
            'has_older' => $hasOlder,
            'has_newer' => $hasNewer,
        ]);
    }

    /**
     * Consulta periódica (sin tiempo real, o como red de seguridad): mensajes nuevos tras
     * ?despues={id}, los cambiados desde ?cambios={instante} en la ventana que tiene cargada el
     * navegador (?desde={id} … ?despues={id}), los fijados y hasta dónde ha leído cada participante.
     */
    public function poll(Request $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);

        /** @var User $user */
        $user = $request->user();
        $after = $this->positiveInt($request->query('despues')) ?? 0;
        $from = $this->positiveInt($request->query('desde')) ?? 0;
        // Un segundo de margen: updated_at se guarda con precisión de segundos.
        $since = $this->instant($request->query('cambios'))?->subSecond();
        $now = now();

        $changes = $this->window->changes($conversation, $after, $from, $since);
        $can = ConversationAbilities::for($user, $conversation);
        $all = $changes['new']->merge($changes['updated']);
        $presented = (new MessagePresenter($user, $can))->present(new EloquentCollection($all->all()));
        $newIds = array_flip($changes['new']->modelKeys());
        $messages = [];
        $updated = [];

        foreach ($presented['messages'] as $message) {
            if (isset($newIds[$message['id']])) {
                $messages[] = $message;
            } else {
                $updated[] = $message;
            }
        }

        return response()->json([
            'messages' => $messages,
            'updated' => $updated,
            'users' => $presented['users'],
            'has_more' => $changes['has_more'],
            'pinned' => PinnedPresenter::list($this->window->pinned($conversation, $can->moderate)),
            'read_state' => array_values(ConversationParticipant::query()
                ->where('conversation_id', $conversation->id)
                ->whereNull('left_at')
                ->get(['user_id', 'last_read_message_id'])
                ->map(fn (ConversationParticipant $participant): array => [
                    'user_id' => $participant->user_id,
                    'last_read_message_id' => $participant->last_read_message_id,
                ])
                ->all()),
            'server_time' => $now->toIso8601ZuluString(),
        ]);
    }

    public function show(Request $request, Message $message): JsonResponse
    {
        Gate::authorize('view', $message->conversation);

        /** @var User $user */
        $user = $request->user();

        return $this->messageResponse($message, $user);
    }

    public function store(StoreMessageRequest $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);

        /** @var User $user */
        $user = $request->user();
        $parentId = $request->integer('parent_id');

        $message = $this->writer->post($user, $conversation, $request->string('body')->toString(), $parentId > 0 ? $parentId : null);

        return $this->messageResponse($message, $user, 201);
    }

    public function update(UpdateMessageRequest $request, Message $message, LinkPreviews $previews): JsonResponse
    {
        Gate::authorize('view', $message->conversation);

        /** @var User $user */
        $user = $request->user();

        $this->writer->edit($user, $message, $request->string('body')->toString());
        $previews->refresh($message);

        return $this->messageResponse($message, $user);
    }

    public function destroy(Request $request, Message $message): JsonResponse
    {
        Gate::authorize('view', $message->conversation);

        /** @var User $user */
        $user = $request->user();

        $this->writer->delete($user, $message);

        return $this->messageResponse($message, $user);
    }

    private function instant(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
