<?php

namespace App\Http\Controllers\Chat;

use App\Domain\Chat\MessageWriter;
use App\Domain\Tasks\TaskOptions;
use App\Domain\Tasks\TaskWriter;
use App\Enums\ConversationType;
use App\Enums\MessageType;
use App\Enums\TranscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\StoreTaskFromMessageRequest;
use App\Http\Resources\Chat\ChatUsers;
use App\Http\Resources\Chat\ConversationPresenter;
use App\Http\Resources\Chat\MessageHtml;
use App\Http\Resources\Chat\MessagePreview;
use App\Models\HourBank;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Crear una tarea desde un mensaje (SPEC §12): solo en el chat de un proyecto y si quien lo pide
 * puede crear tareas en él (TaskPolicy::create). La tarea se crea con TaskWriter (mismas reglas
 * que el alta de la pestaña Tareas: bolsa abierta y obligatoria en proyectos de bolsas…), con el
 * texto del mensaje como descripción, y el mensaje queda enlazado (MessageWriter::linkTask).
 */
class MessageTaskController extends Controller
{
    use RespondsWithMessages;

    public const int TITLE_LENGTH = 120;

    public function __construct(private readonly MessageWriter $writer) {}

    /**
     * Datos del diálogo «Crear tarea»: título sugerido, bolsas abiertas y personas.
     */
    public function options(Request $request, Message $message, TaskOptions $options): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $project = $this->project($user, $message);

        $banks = $project->usesHourBanks()
            ? $options->banks($project, $user)->filter(fn (HourBank $bank): bool => $bank->acceptsTime())->values()
            : collect();
        ['users' => $people, 'memberIds' => $memberIds] = $options->assignableUsers($project, $user);

        return response()->json([
            'title' => $this->suggestedTitle($message),
            'project' => [...ConversationPresenter::project($project), 'uses_hour_banks' => $project->usesHourBanks()],
            'banks' => array_values($banks->map(fn (HourBank $bank): array => [
                'id' => $bank->id,
                'name' => $bank->name,
                'department' => $bank->department?->name,
            ])->all()),
            'people' => array_values($people->map(fn (User $person): array => [
                ...ChatUsers::present($person),
                'is_member' => in_array($person->id, $memberIds, true),
            ])->all()),
        ]);
    }

    public function store(StoreTaskFromMessageRequest $request, Message $message, TaskWriter $tasks): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $project = $this->project($user, $message);

        $task = DB::transaction(function () use ($request, $message, $tasks, $user, $project): Task {
            $task = $tasks->create($user, $project, [
                'title' => trim($request->string('title')->toString()),
                'description' => $this->description($message),
                'hour_bank_id' => $request->input('hour_bank_id'),
                'assignee_user_id' => $request->input('assignee_user_id'),
                'due_date' => $request->input('due_date'),
            ]);

            $this->writer->linkTask($user, $message, $task);

            return $task;
        });

        return $this->messageResponse($message, $user, 201, [
            'task' => ['id' => $task->id, 'title' => $task->title, 'url' => "/tareas/{$task->id}"],
        ]);
    }

    /**
     * Proyecto del chat del mensaje, si se puede crear la tarea: 403 si no ve la conversación o no
     * puede crear tareas en el proyecto; 422 si no es un chat de proyecto o el mensaje no vale.
     */
    private function project(User $user, Message $message): Project
    {
        $conversation = $message->conversation;
        Gate::authorize('view', $conversation);

        $project = $conversation->type === ConversationType::Project ? $conversation->project : null;

        if ($project === null) {
            throw ValidationException::withMessages(['message' => __('conversations.errors.task_not_project')]);
        }

        Gate::authorize('create', [Task::class, $project]);

        if ($message->hidden_at !== null || $message->type === MessageType::System) {
            throw ValidationException::withMessages(['message' => __('conversations.errors.task_unavailable')]);
        }

        if ($message->task_id !== null) {
            throw ValidationException::withMessages(['message' => __('conversations.errors.task_exists')]);
        }

        return $project;
    }

    /**
     * Título sugerido: la primera línea del texto (o de la transcripción de un audio), recortada;
     * si no hay texto, «Mensaje de … en el chat».
     */
    private function suggestedTitle(Message $message): string
    {
        $users = ChatUsers::load([$message->user_id, ...ChatUsers::mentionable([$message])[$message->id] ?? []]);
        $line = trim(strtok((string) $message->body, "\n") ?: '');
        $text = MessagePreview::plain($line, $users, self::TITLE_LENGTH);

        if ($text === '' && $message->type === MessageType::Audio) {
            $transcription = $message->transcription;
            $text = $transcription !== null && $transcription->status === TranscriptionStatus::Done
                ? MessagePreview::plain($transcription->text, [], self::TITLE_LENGTH)
                : '';
        }

        $author = $message->user_id === null ? null : ($users[$message->user_id] ?? null);

        return $text !== '' ? $text : __('conversations.task.untitled', ['name' => $author !== null ? $author->name : __('conversations.unknown_person')]);
    }

    /**
     * Descripción: el texto del mensaje (o la transcripción del audio) y de dónde sale.
     */
    private function description(Message $message): string
    {
        $users = ChatUsers::load([$message->user_id, ...ChatUsers::mentionable([$message])[$message->id] ?? []]);
        $body = (string) $message->body;

        if (trim($body) === '' && $message->type === MessageType::Audio) {
            $transcription = $message->transcription;
            $body = $transcription !== null && $transcription->status === TranscriptionStatus::Done ? (string) $transcription->text : '';
        }

        $author = $message->user_id === null ? null : ($users[$message->user_id] ?? null);
        $origin = __('conversations.task.from_chat', [
            'name' => $author !== null ? $author->name : __('conversations.unknown_person'),
            'date' => $message->created_at?->setTimezone('Europe/Madrid')->format('d/m/Y H:i') ?? '',
        ]);

        return (trim($body) === '' ? '' : MessageHtml::from($body, $users)).'<p>'.e($origin).'</p>';
    }
}
