<?php

namespace App\Http\Controllers\Chat\Media;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\User;
use App\Search\GlobalSearch;
use App\Search\Sources\MessageSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Búsqueda del chat (SPEC §12): /chat/buscar?q= con los mensajes, los nombres de los archivos y las
 * transcripciones de los audios de las conversaciones que quien busca puede ver (MessageSource).
 * - ?conversacion= la limita a una conversación (que tiene que poder ver),
 * - ?tipo=mensajes|archivos|audios, a un tipo de coincidencia,
 * - ?antes= sigue la lista (los resultados van del más reciente al más antiguo); con
 *   Accept: application/json devuelve solo la página siguiente («Ver más»).
 * Cada resultado lleva a /chat/{conversación}?mensaje={id}.
 */
class ChatSearchController extends Controller
{
    public const int PER_PAGE = 20;

    /**
     * ?tipo= (en español en la URL) → tipo de coincidencia.
     */
    public const array KINDS = [
        'mensajes' => MessageSource::KIND_MESSAGE,
        'archivos' => MessageSource::KIND_FILE,
        'audios' => MessageSource::KIND_TRANSCRIPTION,
    ];

    public function __invoke(Request $request, MessageSource $source): Response|JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'conversacion' => ['nullable', 'integer', 'min:1'],
            'tipo' => ['nullable', 'string', Rule::in(array_keys(self::KINDS))],
            'antes' => ['nullable', 'integer', 'min:1'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $query = trim((string) ($validated['q'] ?? ''));
        $type = isset($validated['tipo']) ? (string) $validated['tipo'] : null;
        $before = isset($validated['antes']) ? (int) $validated['antes'] : null;
        $conversation = null;

        if (isset($validated['conversacion'])) {
            $conversation = Conversation::query()->findOrFail((int) $validated['conversacion']);
            Gate::authorize('view', $conversation);
        }

        $hits = mb_strlen($query) >= GlobalSearch::MIN_LENGTH
            ? $source->find($user, $query, self::PER_PAGE + 1, $conversation?->id, $type === null ? null : self::KINDS[$type], $before)
            : [];
        $more = count($hits) > self::PER_PAGE;
        $hits = array_slice($hits, 0, self::PER_PAGE);
        $next = $more && $hits !== [] ? $hits[array_key_last($hits)]['id'] : null;

        if ($request->wantsJson()) {
            return response()->json(['results' => $hits, 'next' => $next]);
        }

        return Inertia::render('chat/buscar', [
            'query' => $query,
            'filters' => [
                'type' => $type,
                'conversation' => $conversation?->id,
            ],
            'conversation' => $conversation === null ? null : [
                'id' => $conversation->id,
                'type' => $conversation->type->value,
                'label' => MessageSource::conversationLabel($conversation, $user),
            ],
            'results' => $hits,
            'next' => $next,
            'minLength' => GlobalSearch::MIN_LENGTH,
        ]);
    }
}
