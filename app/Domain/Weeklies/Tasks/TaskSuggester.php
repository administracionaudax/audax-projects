<?php

namespace App\Domain\Weeklies\Tasks;

use App\Domain\Tasks\TaskWriter;
use App\Domain\Weeklies\Ai\AiDailyLimitReached;
use App\Domain\Weeklies\Ai\AiDailyLimits;
use App\Domain\Weeklies\Ai\LlmClient;
use App\Domain\Weeklies\Ai\LlmRequest;
use App\Enums\AiFeature;
use App\Enums\WeeklyJobState;
use App\Jobs\SuggestTasksFromWeekly;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskArchive;
use App\Models\TaskSuggestionBatch;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklySubmission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * «Generar tareas con IA» (F-062, D-204): a partir de la última weekly cerrada, la IA propone las
 * tareas futuras de quien lo pide (prompt de `extract-tasks`, TaskSuggestionPrompt) y se quitan las
 * que ya tiene (la deduplicación de App.tsx por cliente y descripción parecida).
 *
 * A diferencia de WeeklySync, que las creaba solas y sin proyecto, aquí son PROPUESTAS: se guardan en
 * task_suggestion_batches y la persona las revisa (título, proyecto y bolsa, que se le sugieren por el
 * cliente) antes de crearlas con TaskWriter, en un proyecto en el que puede crear tareas. Nunca se crea
 * una tarea sin que la persona lo confirme.
 */
final class TaskSuggester
{
    /** Una tanda «en cola» o «generando» sin cambios desde hace más se da por atascada (como D-190). */
    public const int STUCK_MINUTES = 12;

    /** Máximo de propuestas que se guardan de una vez. */
    public const int MAX_ITEMS = 50;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly MySpaceTasks $tasks,
        private readonly TaskWriter $writer,
        private readonly AiDailyLimits $limits = new AiDailyLimits,
    ) {}

    /** La fuente: la última weekly cerrada (como `lastClosedWeek` del original, por fecha de fin). */
    public function sourceCycle(): ?WeeklyCycle
    {
        return WeeklyCycle::query()->closed()->orderByDesc('end_date')->orderByDesc('id')->first();
    }

    public function find(User $user): ?TaskSuggestionBatch
    {
        return TaskSuggestionBatch::query()->with('cycle')->where('user_id', $user->id)->first();
    }

    /**
     * Pide una tanda nueva (sustituye a la anterior) y la encola. Con una en marcha, no encola otra.
     *
     * @throws ValidationException sin ninguna weekly cerrada
     * @throws AiDailyLimitReached si ya ha llegado a su límite de hoy (D-222)
     */
    public function request(User $user): TaskSuggestionBatch
    {
        $cycle = $this->sourceCycle();

        if ($cycle === null) {
            throw ValidationException::withMessages(['suggestions' => __('weeklies.tasks.no_closed_weekly')]);
        }

        $batch = $this->find($user);

        if ($batch !== null && self::isBusy($batch)) {
            return $batch;
        }

        // D-222: solo gasta del límite diario lo que de verdad se encola.
        $this->limits->consume($user, AiDailyLimits::SUGGESTED_TASKS);

        $batch ??= new TaskSuggestionBatch(['user_id' => $user->id]);
        $batch->fill(['weekly_cycle_id' => $cycle->id, 'state' => WeeklyJobState::Queued, 'items' => [], 'skipped' => 0, 'error' => null]);
        $batch->updated_at = now();
        $batch->save();

        SuggestTasksFromWeekly::dispatch($batch->id);

        return $batch->setRelation('cycle', $cycle);
    }

    public static function isBusy(TaskSuggestionBatch $batch): bool
    {
        return $batch->state->isBusy()
            && $batch->updated_at !== null
            && $batch->updated_at->greaterThan(now()->subMinutes(self::STUCK_MINUTES));
    }

    /**
     * Genera las propuestas (lo llama el Job). Las excepciones de la IA suben al Job.
     */
    public function generate(TaskSuggestionBatch $batch): void
    {
        $batch->forceFill(['state' => WeeklyJobState::Running, 'error' => null])->save();
        $user = $batch->user;
        $cycle = $batch->cycle;

        if ($cycle === null) {
            $batch->forceFill(['state' => WeeklyJobState::Failed, 'error' => __('weeklies.tasks.no_closed_weekly')])->save();

            return;
        }

        $submissions = WeeklySubmission::query()
            ->where('weekly_cycle_id', $cycle->id)
            ->submitted()
            ->with(['user:id,name', 'entries' => fn ($query) => $query->orderBy('position')->orderBy('id'), 'entries.client' => fn ($query) => $query->withTrashed()->select(['id', 'name'])])
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->get();

        // Como el original: sin reportes, no se llama a la IA.
        if ($submissions->isEmpty()) {
            $batch->forceFill(['state' => WeeklyJobState::Done, 'items' => [], 'skipped' => 0, 'generated_at' => now()])->save();

            return;
        }

        $reports = [];
        $mentionedClients = [];

        foreach ($submissions as $submission) {
            $general = null;
            $byClient = [];

            foreach ($submission->entries as $entry) {
                if ($entry->client_id === null) {
                    $general = $entry->body;

                    continue;
                }

                $mentionedClients[] = $entry->client_id;
                $client = $entry->client;
                $byClient[] = ['client' => $client instanceof Client ? $client->name : 'Cliente desconocido', 'text' => $entry->body];
            }

            $reports[] = TaskSuggestionPrompt::report($submission->user_id, $submission->user->name ?: 'Usuario', $general, $byClient);
        }

        $users = User::query()->internal()->where(fn ($query) => $query->where('is_active', true)->orWhereKey($submissions->pluck('user_id')->all()))
            ->orderBy('name')->get(['id', 'name']);
        $clients = Client::query()->where(fn ($query) => $query->where('is_active', true)->orWhereKey($mentionedClients))
            ->orderBy('name')->get(['id', 'name']);

        $response = $this->llm->generate(new LlmRequest(
            feature: AiFeature::SuggestedTasks,
            prompt: TaskSuggestionPrompt::prompt(
                $user->id,
                $user->name ?: 'Usuario',
                array_values($users->map(fn (User $person): array => ['id' => $person->id, 'name' => $person->name])->all()),
                array_values($clients->map(fn (Client $client): array => ['id' => $client->id, 'name' => $client->name])->all()),
                $reports,
            ),
            responseSchema: TaskSuggestionPrompt::schema(),
            user: $user,
            subject: $cycle,
            operation: 'extract_tasks',
            metadata: ['weekly_cycle_id' => $cycle->id, 'submissions' => $submissions->count()],
        ));

        $proposals = $this->proposals(
            $user,
            TaskSuggestionPrompt::rows($response->json),
            $users->pluck('name', 'id')->all(),
            $clients->pluck('name', 'id')->all(),
        );

        $batch->forceFill([
            'state' => WeeklyJobState::Done,
            'items' => $proposals['items'],
            'skipped' => $proposals['skipped'],
            'error' => null,
            'model' => $response->model,
            'generated_at' => now(),
        ])->save();
    }

    /**
     * Las propuestas de la respuesta de la IA: solo las de quien lo pide, sin las que ya tiene (y sin
     * repetirse entre ellas), con el proyecto y la bolsa sugeridos por el cliente.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<int, string>  $users  id → nombre
     * @param  array<int, string>  $clients  id → nombre
     * @return array{items: list<array<string, mixed>>, skipped: int}
     */
    public function proposals(User $user, array $rows, array $users, array $clients): array
    {
        $existing = $this->existingTasks($user);
        $catalog = $this->tasks->catalog($user);
        $recent = $this->recentProjects($user);
        $items = [];
        $skipped = 0;

        foreach ($rows as $row) {
            $assignee = TaskSuggestionPrompt::id($row['assigneeId'] ?? null);

            // Nunca una tarea para otra persona (el original la habría creado).
            if ($assignee !== null && $assignee !== $user->id) {
                continue;
            }

            $clientId = TaskSuggestionPrompt::id($row['clientId'] ?? null);
            $clientId = $clientId !== null && isset($clients[$clientId]) ? $clientId : null;
            $description = is_string($row['description'] ?? null) ? $row['description'] : '';

            if (TaskSuggestionPrompt::isDuplicate($description, $clientId, $existing)) {
                $skipped++;

                continue;
            }

            $title = Str::limit(trim(preg_replace('/\s+/u', ' ', $description) ?? $description), 250, '…');
            $title = $title !== '' ? $title : 'Tarea sugerida';
            $existing[] = ['title' => $title, 'client_id' => $clientId];

            $authorId = TaskSuggestionPrompt::id($row['assignerId'] ?? null);
            $authorId = $authorId !== null && isset($users[$authorId]) ? $authorId : null;
            [$projectId, $bankId] = $this->suggestProject($catalog, $clientId, $recent);

            $items[] = [
                'key' => (string) Str::uuid(),
                'title' => $title,
                'client_id' => $clientId,
                'client_name' => $clientId !== null ? $clients[$clientId] : null,
                'project_id' => $projectId,
                'hour_bank_id' => $bankId,
                'author_id' => $authorId,
                'author_name' => $authorId !== null ? $users[$authorId] : null,
            ];

            if (count($items) >= self::MAX_ITEMS) {
                break;
            }
        }

        return ['items' => $items, 'skipped' => $skipped];
    }

    /**
     * Crea las propuestas revisadas con TaskWriter (todas o ninguna) y quita de la tanda las creadas y
     * las descartadas. Cada tarea es para quien la revisa, en un proyecto en el que puede crear tareas.
     *
     * @param  list<array{key: string, title: string, project_id: int, hour_bank_id?: int|null, priority?: string|null, due_date?: string|null}>  $accepted
     * @param  list<string>  $dismissed
     * @return list<Task>
     *
     * @throws ValidationException
     */
    public function accept(User $user, TaskSuggestionBatch $batch, array $accepted, array $dismissed = []): array
    {
        $keys = array_column($batch->items ?? [], 'key');
        $created = [];

        DB::transaction(function () use ($user, $accepted, $keys, &$created): void {
            foreach ($accepted as $index => $row) {
                if (! in_array($row['key'], $keys, true)) {
                    throw ValidationException::withMessages(["tasks.{$index}.key" => __('weeklies.tasks.suggestion_gone')]);
                }

                $project = Project::query()->find($row['project_id']);

                if ($project === null || Gate::forUser($user)->denies('create', [Task::class, $project])) {
                    throw ValidationException::withMessages(["tasks.{$index}.project_id" => __('weeklies.tasks.project_forbidden')]);
                }

                try {
                    $created[] = $this->writer->create($user, $project, [
                        'title' => $row['title'],
                        'priority' => $row['priority'] ?? null,
                        'due_date' => $row['due_date'] ?? null,
                        'hour_bank_id' => $row['hour_bank_id'] ?? null,
                        'assignee_user_id' => $user->id,
                    ]);
                } catch (ValidationException $e) {
                    $messages = [];

                    foreach ($e->errors() as $field => $errors) {
                        $messages["tasks.{$index}.{$field}"] = $errors;
                    }

                    throw ValidationException::withMessages($messages);
                }
            }
        });

        $this->remove($batch, [...array_column($accepted, 'key'), ...$dismissed]);

        return $created;
    }

    /**
     * Quita propuestas de la tanda (todas con null).
     *
     * @param  list<string>|null  $keys
     */
    public function remove(TaskSuggestionBatch $batch, ?array $keys = null): void
    {
        $items = $keys === null ? [] : array_values(array_filter(
            $batch->items ?? [],
            fn (array $item): bool => ! in_array($item['key'] ?? null, $keys, true),
        ));

        $batch->forceFill(['items' => $items])->save();
    }

    /**
     * Lo que recibe la página.
     *
     * @return array{state: string, stuck: bool, cycle: array{id: int, number: string, label: string}|null, items: list<array<string, mixed>>, skipped: int, error: string|null, generated_at: string|null}|null
     */
    public static function present(?TaskSuggestionBatch $batch): ?array
    {
        if ($batch === null) {
            return null;
        }

        return [
            'state' => $batch->state->value,
            'stuck' => $batch->state->isBusy() && ! self::isBusy($batch),
            'cycle' => $batch->cycle === null ? null : ['id' => $batch->cycle->id, 'number' => $batch->cycle->number, 'label' => $batch->cycle->label],
            'items' => $batch->items ?? [],
            'skipped' => $batch->skipped,
            'error' => $batch->error,
            'generated_at' => $batch->generated_at?->toIso8601String(),
        ];
    }

    /**
     * Mis tareas para la deduplicación (App.tsx: las asignadas a mí y sin archivar, en cualquier
     * estado), con el cliente de su proyecto.
     *
     * @return list<array{title: string, client_id: int|null}>
     */
    private function existingTasks(User $user): array
    {
        $archived = TaskArchive::query()->where('user_id', $user->id)->select('task_id');

        return array_values(Task::query()
            ->join('projects', 'projects.id', '=', 'tasks.project_id')
            ->where('tasks.assignee_user_id', $user->id)
            ->whereNotIn('tasks.id', $archived)
            ->get(['tasks.title', 'projects.client_id'])
            ->map(fn (Task $task): array => [
                'title' => $task->title,
                'client_id' => $task->getAttribute('client_id') === null ? null : (int) $task->getAttribute('client_id'),
            ])
            ->all());
    }

    /**
     * Mis proyectos por la última fecha en la que imputé en ellos (para sugerir uno si el cliente
     * tiene varios).
     *
     * @return array<int, string> proyecto → última fecha
     */
    private function recentProjects(User $user): array
    {
        return DB::table('time_entries')
            ->where('user_id', $user->id)
            ->groupBy('project_id')
            ->selectRaw('project_id, MAX(date) as last_on')
            ->pluck('last_on', 'project_id')
            ->mapWithKeys(fn ($date, $id): array => [(int) $id => substr((string) $date, 0, 10)])
            ->all();
    }

    /**
     * El proyecto sugerido para un cliente: el único en el que puedo crear tareas o, si hay varios,
     * aquel en el que imputé más recientemente (si no, el primero por código). Y su bolsa: la primera
     * abierta del departamento de la persona, o la primera abierta.
     *
     * @param  list<array<string, mixed>>  $catalog  MySpaceTasks::catalog()
     * @param  array<int, string>  $recent
     * @return array{0: int|null, 1: int|null}
     */
    private function suggestProject(array $catalog, ?int $clientId, array $recent): array
    {
        $candidates = array_values(array_filter($catalog, fn (array $project): bool => ($project['client']['id'] ?? null) === $clientId));

        if ($candidates === [] || ($clientId === null && count($candidates) > 1)) {
            return [null, null];
        }

        usort($candidates, fn (array $a, array $b): int => [$recent[$b['id']] ?? '', $a['code']] <=> [$recent[$a['id']] ?? '', $b['code']]);
        $project = $candidates[0];
        $bank = $project['uses_banks'] ? ($project['banks'][0]['id'] ?? null) : null;

        return [$project['id'], $bank];
    }
}
