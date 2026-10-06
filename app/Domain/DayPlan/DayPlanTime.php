<?php

namespace App\Domain\DayPlan;

use App\Domain\Tasks\TaskWriter;
use App\Domain\Time\TimeEntryData;
use App\Domain\Time\TimeEntryResult;
use App\Domain\Time\TimeEntryWriter;
use App\Domain\Time\TimerService;
use App\Enums\DayPlanItemStatus;
use App\Models\DayPlanItem;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Las horas de una línea del plan del día (docs/PLAN-CARGAS.md §6.1.4; D-254). Una hora vive SIEMPRE
 * en una tarea (`active_timers.task_id` y `time_entries.task_id` siguen obligatorios): la línea
 * acaba apuntando a una tarea —la suya, una elegida o una nueva con su texto— y las entradas solo
 * llevan además el enlace `day_plan_item_id`. Todo se escribe con TimerService y TimeEntryWriter,
 * con todas sus reglas (miembro, bolsa, semana cerrada, fecha futura…).
 *
 * - **▶ desde la línea:** con tarea, esa; si no, la que se elija («¿En qué tarea?») o una nueva
 *   «<texto de la línea>» en el proyecto de la línea (TaskWriter, asignada a quien la crea y con la
 *   bolsa por defecto de su departamento si el proyecto es de bolsas). La tarea queda en la línea.
 * - **«Imputar lo previsto»:** una línea hecha, con tarea y horas previstas y sin horas imputa esas
 *   horas en su día (borrador). En bloque, todas las del día que lo cumplan.
 * - **«Vincular horas»:** elegir entradas mías de ese día sin línea (o quitar las de esta línea).
 */
final class DayPlanTime
{
    public function __construct(
        private readonly DayPlanWriter $writer,
        private readonly TimerService $timers,
        private readonly TimeEntryWriter $entries,
        private readonly TaskWriter $tasks,
    ) {}

    /**
     * Arranca el temporizador desde la línea. Devuelve las entradas del temporizador anterior.
     *
     * @return list<TimeEntryResult>
     *
     * @throws ValidationException
     */
    public function start(User $user, DayPlanItem $item, ?int $taskId = null, bool $createTask = false): array
    {
        $this->writer->assertLoggable($user, $item);

        return DB::transaction(function () use ($user, $item, $taskId, $createTask): array {
            $task = match (true) {
                $taskId !== null => $this->chosenTask($user, $taskId),
                $createTask => $this->createTask($user, $item),
                $item->task_id !== null => $this->chosenTask($user, $item->task_id),
                default => throw ValidationException::withMessages(['task_id' => __('day_plan.errors.choose_task')]),
            };

            $previous = $this->timers->start($user, $task, null, $item->id);
            $this->writer->adoptTask($item, $task);

            return $previous;
        });
    }

    /**
     * «Imputar lo previsto» de una línea.
     *
     * @throws ValidationException
     */
    public function logPlanned(User $user, DayPlanItem $item): TimeEntryResult
    {
        $this->writer->assertLoggable($user, $item);

        if ($item->status !== DayPlanItemStatus::Done || $item->task_id === null || $item->planned_minutes === null) {
            throw ValidationException::withMessages(['item' => __('day_plan.errors.log_planned')]);
        }

        if (TimeEntry::query()->where('day_plan_item_id', $item->id)->exists()) {
            throw ValidationException::withMessages(['item' => __('day_plan.errors.already_logged')]);
        }

        return $this->entries->create($user, new TimeEntryData(
            userId: $user->id,
            taskId: $item->task_id,
            date: CarbonImmutable::parse($item->date->toDateString()),
            minutes: $item->planned_minutes,
            dayPlanItemId: $item->id,
        ));
    }

    /**
     * «Imputar lo previsto de N líneas hechas sin horas» de un día. Cada línea va por su cuenta: si
     * una no se puede imputar (semana enviada, bolsa sin saldo…), las demás sí.
     *
     * @return array{logged: int, minutes: int, failed: list<array{text: string, message: string}>}
     */
    public function logPlannedDay(User $user, string $date): array
    {
        $result = ['logged' => 0, 'minutes' => 0, 'failed' => []];

        foreach ($this->pendingLogs($user, $date) as $item) {
            try {
                $entry = $this->logPlanned($user, $item);
                $result['logged']++;
                $result['minutes'] += $entry->entry->minutes;
            } catch (ValidationException $exception) {
                $result['failed'][] = ['text' => $item->text, 'message' => (string) collect($exception->errors())->flatten()->first()];
            }
        }

        return $result;
    }

    /**
     * Líneas de un día hechas, con tarea y horas previstas y sin horas imputadas.
     *
     * @return list<DayPlanItem>
     */
    public function pendingLogs(User $user, string $date): array
    {
        return array_values(DayPlanItem::query()
            ->where('user_id', $user->id)
            ->where('date', $date)
            ->where('status', DayPlanItemStatus::Done->value)
            ->whereNotNull('task_id')
            ->whereNotNull('planned_minutes')
            ->whereDoesntHave('timeEntries')
            ->orderBy('position')
            ->get()
            ->all());
    }

    /**
     * Mis entradas del día de la línea que se pueden vincular: las suyas y las que no tienen línea.
     *
     * @return list<array{id: int, minutes: int, description: string|null, task: string, project: string, linked: bool, locked: bool}>
     */
    public function linkable(User $user, DayPlanItem $item): array
    {
        return array_values(TimeEntry::query()
            ->where('user_id', $user->id)
            ->where('date', $item->date->toDateString())
            ->where(fn ($query) => $query->whereNull('day_plan_item_id')->orWhere('day_plan_item_id', $item->id))
            ->with(['task' => fn ($query) => $query->withTrashed()->select(['id', 'title']), 'project' => fn ($query) => $query->withTrashed()->select(['id', 'code'])])
            ->orderBy('id')
            ->get()
            ->map(fn (TimeEntry $entry): array => [
                'id' => $entry->id,
                'minutes' => $entry->minutes,
                'description' => $entry->description,
                'task' => $entry->task->title,
                'project' => $entry->project->code,
                'linked' => $entry->day_plan_item_id === $item->id,
                'locked' => $entry->isLocked(),
            ])
            ->all());
    }

    /**
     * Deja enlazadas con la línea exactamente $entryIds (de las que se pueden vincular).
     *
     * @param  list<int>  $entryIds
     *
     * @throws ValidationException
     */
    public function link(User $user, DayPlanItem $item, array $entryIds): int
    {
        $this->writer->assertLoggable($user, $item);
        $candidates = TimeEntry::query()
            ->where('user_id', $user->id)
            ->where('date', $item->date->toDateString())
            ->where(fn ($query) => $query->whereNull('day_plan_item_id')->orWhere('day_plan_item_id', $item->id))
            ->get()
            ->keyBy('id');

        if (array_diff($entryIds, $candidates->keys()->map(fn ($id): int => (int) $id)->all()) !== []) {
            throw ValidationException::withMessages(['entry_ids' => __('day_plan.errors.entry_not_linkable')]);
        }

        return DB::transaction(function () use ($user, $item, $entryIds, $candidates): int {
            $linked = 0;

            foreach ($candidates as $entry) {
                $wanted = in_array($entry->id, $entryIds, true);

                if ($wanted === ($entry->day_plan_item_id === $item->id)) {
                    $linked += $wanted ? 1 : 0;

                    continue;
                }

                $this->entries->linkDayPlanItem($user, $entry, $wanted ? $item->id : null);
                $linked += $wanted ? 1 : 0;
            }

            if ($linked > 0 && $item->task_id === null) {
                $first = $candidates->first(fn (TimeEntry $entry): bool => in_array($entry->id, $entryIds, true));

                if ($first !== null) {
                    $this->writer->adoptTask($item, Task::query()->withTrashed()->findOrFail($first->task_id));
                }
            }

            return $linked;
        });
    }

    /**
     * @throws ValidationException
     */
    private function chosenTask(User $user, int $taskId): Task
    {
        $task = Task::query()->find($taskId);

        if ($task === null || ! Gate::forUser($user)->allows('view', $task)) {
            throw ValidationException::withMessages(['task_id' => __('day_plan.errors.task')]);
        }

        return $task;
    }

    /**
     * Nueva tarea «<texto de la línea>» en el proyecto de la línea, asignada a quien la crea.
     *
     * @throws ValidationException
     */
    private function createTask(User $user, DayPlanItem $item): Task
    {
        $project = $item->project_id === null ? null : Project::query()->find($item->project_id);

        if ($project === null) {
            throw ValidationException::withMessages(['task_id' => __('day_plan.errors.no_project')]);
        }

        if (! Gate::forUser($user)->allows('create', [Task::class, $project])) {
            throw ValidationException::withMessages(['task_id' => __('day_plan.errors.cannot_create_task', ['project' => $project->code])]);
        }

        return $this->tasks->create($user, $project, [
            'title' => $item->text,
            'assignee_user_id' => $user->id,
            'hour_bank_id' => $this->defaultBank($user, $project)?->id,
        ]);
    }

    /**
     * Bolsa de una tarea nueva en un proyecto de bolsas: la abierta del departamento de la persona;
     * si no hay, la abierta sin departamento; si solo hay una abierta, esa. Si no se puede decidir,
     * se pide elegir una tarea.
     *
     * @throws ValidationException
     */
    public function defaultBank(User $user, Project $project): ?HourBank
    {
        if (! $project->usesHourBanks()) {
            return null;
        }

        $open = HourBank::query()
            ->where('project_id', $project->id)
            ->orderByDesc('start_date')
            ->get()
            ->filter(fn (HourBank $bank): bool => $bank->acceptsTime())
            ->values();

        $bank = $open->firstWhere('department_id', $user->department_id)
            ?? ($open->whereNull('department_id')->count() === 1 ? $open->whereNull('department_id')->first() : null)
            ?? ($open->count() === 1 ? $open->first() : null);

        if ($bank === null) {
            throw ValidationException::withMessages(['task_id' => __('day_plan.errors.no_bank', ['project' => $project->code])]);
        }

        return $bank;
    }
}
