<?php

namespace App\Http\Controllers\Weeklies;

use App\Domain\Weeklies\Assistant\AssistantContext;
use App\Domain\Weeklies\Assistant\AssistantQuestions;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WeeklyEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Asistente IA (/ia, F-006, F-146 y F-147; D-205 y D-206): preguntas sobre las weeklies, los clientes,
 * las tareas, el estado de los proyectos y las horas con SOLO los datos que puede ver quien pregunta
 * (D-146, AssistantContext). La pregunta se responde en la cola `ai` y la página espera la respuesta
 * por Reverb o preguntando por ella. La conversación es de la sesión (la guarda el navegador).
 */
class AssistantController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('use-weeklies');

        /** @var User $user */
        $user = $request->user();

        return Inertia::render('assistant/index', [
            'suggested_questions' => $this->suggestedQuestions($user),
            'scope' => AssistantContext::scope($user),
            'max_question' => AssistantQuestions::MAX_QUESTION,
        ]);
    }

    public function ask(Request $request, AssistantQuestions $questions): JsonResponse
    {
        Gate::authorize('use-weeklies');

        $data = $request->validate([
            'question' => ['required', 'string', 'max:'.AssistantQuestions::MAX_QUESTION],
            'history' => ['sometimes', 'array', 'max:20'],
            'history.*.role' => ['required', 'string', 'in:user,assistant'],
            'history.*.content' => ['required', 'string', 'max:8000'],
        ], ['question.required' => __('weeklies.assistant.question_required')]);

        /** @var User $user */
        $user = $request->user();
        $history = array_values(array_map(fn (array $message): array => [
            'role' => (string) $message['role'],
            'content' => (string) $message['content'],
        ], $data['history'] ?? []));

        $question = $questions->ask($user, trim((string) $data['question']), $history);

        return response()->json(['question' => $question], 202);
    }

    public function show(Request $request, string $question, AssistantQuestions $questions): JsonResponse
    {
        Gate::authorize('use-weeklies');

        /** @var User $user */
        $user = $request->user();
        $found = $questions->find($user, $question);

        abort_if($found === null, 404, __('weeklies.assistant.not_found'));

        return response()->json(['question' => AssistantQuestions::present($found)]);
    }

    /**
     * Las preguntas sugeridas de WeeklySync, con nombres de Audax: el cliente con el último reporte, el
     * departamento de quien pregunta y la última persona que ha enviado su weekly.
     *
     * @return list<string>
     */
    private function suggestedQuestions(User $user): array
    {
        $client = WeeklyEntry::query()
            ->whereNotNull('weekly_entries.client_id')
            ->join('weekly_submissions', 'weekly_submissions.id', '=', 'weekly_entries.weekly_submission_id')
            ->join('clients', 'clients.id', '=', 'weekly_entries.client_id')
            ->whereNotNull('weekly_submissions.submitted_at')
            ->whereNull('clients.deleted_at')
            ->orderByDesc('weekly_submissions.submitted_at')
            ->value('clients.name');

        $person = User::query()
            ->join('weekly_submissions', 'weekly_submissions.user_id', '=', 'users.id')
            ->whereNotNull('weekly_submissions.submitted_at')
            ->where('users.id', '!=', $user->id)
            ->where('users.is_active', true)
            ->orderByDesc('weekly_submissions.submitted_at')
            ->value('users.name');

        $department = $user->department?->name;

        return [
            is_string($client) ? __('weeklies.assistant.suggested.client', ['client' => $client]) : __('weeklies.assistant.suggested.clients'),
            $department !== null ? __('weeklies.assistant.suggested.department', ['department' => $department]) : __('weeklies.assistant.suggested.team'),
            is_string($person) ? __('weeklies.assistant.suggested.person', ['person' => $person]) : __('weeklies.assistant.suggested.me'),
            __('weeklies.assistant.suggested.problems'),
            __('weeklies.assistant.suggested.risk'),
        ];
    }
}
