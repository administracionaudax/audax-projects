<?php

namespace App\Domain\Weeklies\Assistant;

use App\Domain\Weeklies\Ai\LlmClient;
use App\Domain\Weeklies\Ai\LlmException;
use App\Domain\Weeklies\Ai\LlmRequest;
use App\Enums\AiFeature;
use App\Enums\WeeklyJobState;
use App\Events\Weeklies\AssistantAnswered;
use App\Jobs\AnswerAssistantQuestion;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Las preguntas al asistente (F-146, D-205 y D-206). Una pregunta se responde en la cola `ai`
 * (AnswerAssistantQuestion, D-146): la página la manda, recibe su id y espera la respuesta por Reverb
 * (`assistant.answered` en el canal privado de quien pregunta) o preguntando por ella.
 *
 * La pregunta y la respuesta viven en la caché una hora (QUESTION_TTL): la conversación es de la
 * sesión, como en WeeklySync, y la guarda el navegador (sessionStorage); el servidor no conserva
 * historial. Solo quien pregunta ve su pregunta. Cada llamada a Gemini queda en ai_usage (sin el
 * texto).
 */
final class AssistantQuestions
{
    public const int QUESTION_TTL = 3600;

    public const int MAX_QUESTION = 2000;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly AssistantContext $context,
    ) {}

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @return array{id: string, state: string, answer: string|null, error: string|null}
     */
    public function ask(User $user, string $question, array $history = []): array
    {
        $id = (string) Str::uuid();

        $this->put([
            'id' => $id,
            'user_id' => $user->id,
            'question' => $question,
            'history' => $history,
            'state' => WeeklyJobState::Queued->value,
            'answer' => null,
            'error' => null,
            'created_at' => now()->toIso8601String(),
        ]);

        AnswerAssistantQuestion::dispatch($id);

        return self::present($this->get($id) ?? []);
    }

    /**
     * La pregunta, solo para quien la hizo.
     *
     * @return array<string, mixed>|null
     */
    public function find(User $user, string $id): ?array
    {
        $question = $this->get($id);

        return $question !== null && ($question['user_id'] ?? null) === $user->id ? $question : null;
    }

    /**
     * Responde (lo llama el Job): el contexto de quien pregunta, el prompt y Gemini. Un fallo deja la
     * pregunta con el mensaje de error; nunca sube.
     */
    public function answer(string $id): void
    {
        $question = $this->get($id);

        if ($question === null || ! in_array($question['state'], [WeeklyJobState::Queued->value, WeeklyJobState::Running->value], true)) {
            return;
        }

        $user = User::query()->find((int) $question['user_id']);

        if ($user === null || ! $user->is_active) {
            $this->finish($question, WeeklyJobState::Failed, null, __('weeklies.assistant.failed'));

            return;
        }

        $question['state'] = WeeklyJobState::Running->value;
        $this->put($question);

        try {
            $prompt = AssistantPrompt::prompt(
                AssistantPrompt::contextText($this->context->for($user)),
                (string) $question['question'],
                $user->name,
                self::history($question['history'] ?? null),
            );

            $response = $this->llm->generate(new LlmRequest(
                feature: AiFeature::Assistant,
                prompt: $prompt,
                user: $user,
                operation: 'knowledge_base_query',
                metadata: ['query_length' => mb_strlen((string) $question['question']), 'history' => count($question['history'] ?? [])],
            ));

            $answer = trim($response->text);
            $this->finish($question, WeeklyJobState::Done, $answer !== '' ? $answer : __('weeklies.assistant.empty'), null);
        } catch (LlmException $e) {
            $this->finish($question, WeeklyJobState::Failed, null, $e->userMessage());
        } catch (Throwable $e) {
            report($e);
            $this->finish($question, WeeklyJobState::Failed, null, __('weeklies.assistant.failed'));
        }
    }

    /**
     * La conversación anterior guardada con la pregunta, con la forma que espera el prompt.
     *
     * @return list<array{role: string, content: string}>
     */
    private static function history(mixed $history): array
    {
        $result = [];

        foreach (is_array($history) ? $history : [] as $message) {
            if (is_array($message) && is_string($message['role'] ?? null) && is_string($message['content'] ?? null)) {
                $result[] = ['role' => $message['role'], 'content' => $message['content']];
            }
        }

        return $result;
    }

    /**
     * Lo que recibe la página.
     *
     * @param  array<string, mixed>  $question
     * @return array{id: string, state: string, answer: string|null, error: string|null}
     */
    public static function present(array $question): array
    {
        return [
            'id' => (string) ($question['id'] ?? ''),
            'state' => (string) ($question['state'] ?? WeeklyJobState::Failed->value),
            'answer' => isset($question['answer']) ? (string) $question['answer'] : null,
            'error' => isset($question['error']) ? (string) $question['error'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $question
     */
    private function finish(array $question, WeeklyJobState $state, ?string $answer, ?string $error): void
    {
        $question['state'] = $state->value;
        $question['answer'] = $answer;
        $question['error'] = $error;
        $question['answered_at'] = now()->toIso8601String();
        $this->put($question);

        event(new AssistantAnswered((int) $question['user_id'], (string) $question['id'], $state->value));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function get(string $id): ?array
    {
        $value = Cache::get(self::key($id));

        return is_array($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $question
     */
    private function put(array $question): void
    {
        Cache::put(self::key((string) $question['id']), $question, self::QUESTION_TTL);
    }

    private static function key(string $id): string
    {
        return 'assistant:question:'.$id;
    }
}
