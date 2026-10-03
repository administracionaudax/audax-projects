<?php

namespace App\Domain\Tasks;

use App\Enums\TaskPriority;
use App\Models\ActiveTimer;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TaskType;
use App\Models\User;
use App\Support\RichText;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Alta y edición de tareas con las reglas del dominio (SPEC §6 y §8.2, D-037):
 * - en los proyectos de bolsas, la bolsa es obligatoria y debe estar abierta (activa o agotada),
 * - subtareas de un solo nivel, en el proyecto y con la bolsa de su padre (siempre),
 * - si una tarea tiene subtareas con estimación, la suya es la suma (solo lectura),
 * - un hito no lleva estimación ni horas ni inicio: su única fecha es la entrega (D-062); una
 *   tarea con horas no puede pasar a hito,
 * - no cambia de bolsa con un temporizador en marcha en ella o en sus subtareas (hasRunningTimer),
 * - completed_at lo gestiona el modelo al cambiar de estado,
 * - las tareas nuevas van al final de su columna; al cambiar de estado, al final de la nueva,
 * - el creador y el responsable siguen la tarea; asignación, menciones y cambios de estado avisan
 *   (TaskNotifier); las menciones de la descripción, filtradas como las de los comentarios
 *   (TaskMentions, D-134).
 * La autorización (TaskPolicy) la hace el controlador.
 */
final class TaskWriter
{
    public function __construct(
        private readonly TaskPositions $positions,
        private readonly TaskNotifier $notifier,
        private readonly TaskMentions $mentions,
    ) {}

    /**
     * @param  array<string, mixed>  $data  datos validados (StoreTaskRequest)
     *
     * @throws ValidationException
     */
    public function create(User $actor, Project $project, array $data): Task
    {
        $parent = $this->parent($project, $data['parent_task_id'] ?? null);
        $bank = $parent !== null
            ? $this->parentBank($parent)
            : $this->assertBank($project, $this->intOrNull($data['hour_bank_id'] ?? null));
        $type = $this->type($this->intOrNull($data['task_type_id'] ?? null));
        $statusId = $this->statusId($this->intOrNull($data['status_id'] ?? null));
        $assigneeId = $this->assigneeId($this->intOrNull($data['assignee_user_id'] ?? null), $project, $actor);
        $isMilestone = (bool) ($data['is_milestone'] ?? false);
        $startDate = $this->dateOrNull($data['start_date'] ?? null);
        $dueDate = $this->dateOrNull($data['due_date'] ?? null);
        $this->assertDates($startDate, $dueDate);

        // Un hito solo tiene entrega (D-062): si solo traía inicio, esa fecha pasa a ser la entrega.
        if ($isMilestone) {
            $dueDate ??= $startDate;
            $startDate = null;
        }

        $description = RichText::sanitize($this->stringOrNull($data['description'] ?? null));

        $task = $this->notifier->capture(fn (): Task => DB::transaction(function () use ($actor, $project, $data, $parent, $bank, $type, $statusId, $assigneeId, $isMilestone, $startDate, $dueDate, $description): Task {
            $task = new Task([
                'project_id' => $project->id,
                'hour_bank_id' => $bank?->id,
                'parent_task_id' => $parent?->id,
                'title' => trim((string) $data['title']),
                'description' => $description,
                'task_type_id' => $type?->id,
                'status_id' => $statusId,
                'priority' => TaskPriority::tryFrom((string) ($data['priority'] ?? '')) ?? TaskPriority::Normal,
                'assignee_user_id' => $assigneeId,
                'start_date' => $startDate,
                'due_date' => $dueDate,
                'estimated_minutes' => $isMilestone ? null : $this->intOrNull($data['estimated_minutes'] ?? null),
                'is_billable' => array_key_exists('is_billable', $data) && $data['is_billable'] !== null
                    ? (bool) $data['is_billable']
                    : $this->defaultBillable($project, $type),
                'is_milestone' => $isMilestone,
                'position' => $this->positions->next($project->id, $statusId, $parent?->id),
                'created_by' => $actor->id,
            ]);
            $task->save();
            $task->setRelation('project', $project);

            $task->watchers()->syncWithoutDetaching(array_values(array_filter([$actor->id, $assigneeId])));

            $assigned = $this->notifier->assigned($task, $actor);
            $mentioned = $this->mentions->mentionable(RichText::mentionedUserIds($description), $project->id, $actor);
            $this->notifier->mentioned($task, $actor, array_values(array_diff($mentioned, $assigned)), 'description', $description);

            return $task;
        }));

        return $task;
    }

    /**
     * Cambios parciales: solo las claves presentes en $data.
     *
     * @param  array<string, mixed>  $data  datos validados (UpdateTaskRequest o acción masiva)
     *
     * @throws ValidationException
     */
    public function update(User $actor, Task $task, array $data): Task
    {
        $task->loadMissing('project');
        $project = $task->project;
        $previousDescription = $task->description;
        $previousStatusId = $task->status_id;
        $previousAssigneeId = $task->assignee_user_id;
        $previousBankId = $task->hour_bank_id;

        if (array_key_exists('title', $data)) {
            $task->title = trim((string) $data['title']);
        }

        if (array_key_exists('description', $data)) {
            $task->description = RichText::sanitize($this->stringOrNull($data['description']));
        }

        if (array_key_exists('status_id', $data)) {
            $task->status_id = $this->statusId($this->intOrNull($data['status_id']));
        }

        if (array_key_exists('priority', $data)) {
            $task->priority = TaskPriority::tryFrom((string) $data['priority']) ?? $task->priority;
        }

        if (array_key_exists('assignee_user_id', $data)) {
            $assigneeId = $this->intOrNull($data['assignee_user_id']);
            $task->assignee_user_id = $assigneeId === $previousAssigneeId ? $assigneeId : $this->assigneeId($assigneeId, $project, $actor);
        }

        if (array_key_exists('task_type_id', $data)) {
            $typeId = $this->intOrNull($data['task_type_id']);
            $type = $typeId === $task->task_type_id ? null : $this->type($typeId);

            if ($typeId !== $task->task_type_id) {
                $task->task_type_id = $type?->id;

                // Al elegir un tipo, la tarea hereda si es facturable (editable después, SPEC §4.3).
                if (! array_key_exists('is_billable', $data)) {
                    $task->is_billable = $this->defaultBillable($project, $type);
                }
            }
        }

        if (array_key_exists('is_billable', $data)) {
            $task->is_billable = (bool) $data['is_billable'];
        }

        if (array_key_exists('start_date', $data)) {
            $task->start_date = $this->dateOrNull($data['start_date']);
        }

        if (array_key_exists('due_date', $data)) {
            $task->due_date = $this->dateOrNull($data['due_date']);
        }

        $this->assertDates($task->start_date, $task->due_date);

        if (array_key_exists('is_milestone', $data)) {
            $isMilestone = (bool) $data['is_milestone'];

            if ($isMilestone && ! $task->is_milestone && $task->timeEntries()->exists()) {
                throw ValidationException::withMessages(['is_milestone' => __('tasks.errors.milestone_with_time')]);
            }

            $task->is_milestone = $isMilestone;
        }

        if (array_key_exists('estimated_minutes', $data)) {
            $estimate = $this->intOrNull($data['estimated_minutes']);

            if ($estimate !== $task->estimated_minutes && $this->estimateComesFromSubtasks($task)) {
                throw ValidationException::withMessages(['estimated_minutes' => __('tasks.errors.estimate_from_subtasks')]);
            }

            $task->estimated_minutes = $estimate;
        }

        // Un hito no tiene duración: sin estimación y con la entrega como única fecha (D-062). Si
        // solo tenía inicio, esa fecha pasa a ser la entrega.
        if ($task->is_milestone) {
            $task->estimated_minutes = null;
            $task->due_date ??= $task->start_date;
            $task->start_date = null;
        }

        $bankChanged = false;

        if (array_key_exists('hour_bank_id', $data)) {
            $bankId = $this->intOrNull($data['hour_bank_id']);

            if ($bankId !== $previousBankId) {
                if ($task->isSubtask()) {
                    throw ValidationException::withMessages(['hour_bank_id' => __('tasks.errors.subtask_bank')]);
                }

                if ($this->hasRunningTimer($task)) {
                    throw ValidationException::withMessages(['hour_bank_id' => __('tasks.errors.bank_timer_running')]);
                }

                $task->hour_bank_id = $this->assertBank($project, $bankId)?->id;
                $bankChanged = true;
            }
        }

        $statusChanged = $task->status_id !== $previousStatusId;
        $assigneeChanged = $task->assignee_user_id !== $previousAssigneeId && $task->assignee_user_id !== null;
        // Las mismas reglas que en los comentarios (TaskMentions, D-134).
        $newMentions = array_key_exists('description', $data)
            ? $this->mentions->mentionable(
                array_values(array_diff(RichText::mentionedUserIds($task->description), RichText::mentionedUserIds($previousDescription))),
                $task->project_id,
                $actor,
            )
            : [];

        return $this->notifier->capture(fn (): Task => DB::transaction(function () use ($actor, $task, $statusChanged, $assigneeChanged, $bankChanged, $newMentions): Task {
            if ($statusChanged && ! $task->isSubtask()) {
                $task->position = $this->positions->next($task->project_id, $task->status_id);
            }

            $task->save();

            // Las subtareas siempre descuentan de la bolsa de su padre (D-037); sus horas no se mueven.
            if ($bankChanged) {
                foreach ($task->subtasks()->get() as $subtask) {
                    $subtask->hour_bank_id = $task->hour_bank_id;
                    $subtask->save();
                }
            }

            $notified = [];

            if ($assigneeChanged) {
                $task->watchers()->syncWithoutDetaching([(int) $task->assignee_user_id]);
                $notified = $this->notifier->assigned($task, $actor);
            }

            if ($newMentions !== []) {
                $this->notifier->mentioned($task, $actor, array_values(array_diff($newMentions, $notified)), 'description', $task->description);
            }

            if ($statusChanged) {
                $status = TaskStatus::query()->findOrFail($task->status_id);
                $this->notifier->statusChanged($task, $actor, $status, $notified);
            }

            return $task;
        }));
    }

    /**
     * ¿Hay un temporizador en marcha en la tarea o en alguna de sus subtareas? Mientras lo haya, la
     * tarea no cambia de proyecto ni de bolsa: al pararlo, TimerService imputa con el proyecto y la
     * bolsa que tenga la tarea en ese momento, y las horas medidas antes del cambio acabarían en el
     * destino (SPEC §6: mover una tarea no mueve sus horas).
     */
    public function hasRunningTimer(Task $task): bool
    {
        return ActiveTimer::query()
            ->where(fn (Builder $query) => $query->where('task_id', $task->id)->orWhereIn('task_id', $task->subtasks()->select('id')))
            ->exists();
    }

    /**
     * La estimación del padre es la suma de sus subtareas si alguna tiene estimación (SPEC §6).
     */
    public function estimateComesFromSubtasks(Task $task): bool
    {
        if ($task->isSubtask()) {
            return false;
        }

        return $task->relationLoaded('subtasks')
            ? $task->subtasks->whereNotNull('estimated_minutes')->isNotEmpty()
            : $task->subtasks()->whereNotNull('estimated_minutes')->exists();
    }

    /**
     * Bolsa válida para una tarea del proyecto: obligatoria en proyectos de bolsas, del mismo
     * proyecto y abierta (activa o agotada). Las cerradas y renovadas ya no admiten tareas nuevas.
     *
     * @throws ValidationException
     */
    public function assertBank(Project $project, ?int $bankId, string $field = 'hour_bank_id'): ?HourBank
    {
        if ($bankId === null) {
            if ($project->usesHourBanks()) {
                throw ValidationException::withMessages([$field => __('tasks.errors.bank_required')]);
            }

            return null;
        }

        $bank = HourBank::query()->whereKey($bankId)->where('project_id', $project->id)->first();

        if ($bank === null) {
            throw ValidationException::withMessages([$field => __('tasks.errors.bank_not_in_project')]);
        }

        if (! $bank->acceptsTime()) {
            throw ValidationException::withMessages([$field => __('tasks.errors.bank_closed', [
                'bank' => $bank->name,
                'status' => mb_strtolower($bank->status->label()),
            ])]);
        }

        return $bank;
    }

    /**
     * Bolsa de una subtarea nueva: la del padre (D-037), que tiene que admitir horas. En una bolsa
     * cerrada o renovada se crearía una tarea abierta en la que no se puede imputar: antes hay que
     * mover el padre a una bolsa abierta.
     *
     * @throws ValidationException
     */
    private function parentBank(Task $parent): ?HourBank
    {
        $bank = $this->bankById($parent->hour_bank_id);

        if ($bank !== null && ! $bank->acceptsTime()) {
            throw ValidationException::withMessages(['parent_task_id' => __('tasks.errors.parent_bank_closed', [
                'bank' => $bank->name,
                'status' => mb_strtolower($bank->status->label()),
            ])]);
        }

        return $bank;
    }

    /**
     * @throws ValidationException
     */
    private function parent(Project $project, mixed $parentId): ?Task
    {
        $id = $this->intOrNull($parentId);

        if ($id === null) {
            return null;
        }

        $parent = Task::query()->whereKey($id)->where('project_id', $project->id)->first();

        if ($parent === null) {
            throw ValidationException::withMessages(['parent_task_id' => __('tasks.errors.parent_not_found')]);
        }

        if ($parent->isSubtask()) {
            throw ValidationException::withMessages(['parent_task_id' => __('tasks.errors.single_level')]);
        }

        return $parent;
    }

    private function bankById(?int $bankId): ?HourBank
    {
        return $bankId === null ? null : HourBank::query()->withTrashed()->find($bankId);
    }

    /**
     * @throws ValidationException
     */
    private function type(?int $typeId): ?TaskType
    {
        if ($typeId === null) {
            return null;
        }

        $type = TaskType::query()->active()->find($typeId);

        if ($type === null) {
            throw ValidationException::withMessages(['task_type_id' => __('tasks.errors.type_invalid')]);
        }

        return $type;
    }

    /**
     * @throws ValidationException
     */
    private function statusId(?int $statusId): int
    {
        if ($statusId === null) {
            return TaskStatus::defaultStatus()->id;
        }

        if (! TaskStatus::query()->whereKey($statusId)->exists()) {
            throw ValidationException::withMessages(['status_id' => __('tasks.errors.status_invalid')]);
        }

        return $statusId;
    }

    /**
     * El responsable es siempre una persona interna y activa. Colaboradores externos (D-134): uno
     * solo es responsable en los proyectos de los que es miembro, y uno que asigna solo elige
     * entre los miembros del proyecto (no ve a nadie más).
     *
     * @throws ValidationException
     */
    private function assigneeId(?int $userId, Project $project, User $actor): ?int
    {
        if ($userId === null) {
            return null;
        }

        $assignee = User::query()->whereKey($userId)->active()->internal()->first();
        $outsider = $assignee !== null
            && ($assignee->isCollaborator() || $actor->isCollaborator())
            && ! $project->members()->whereKey($assignee->id)->exists();

        if ($assignee === null || $outsider) {
            throw ValidationException::withMessages(['assignee_user_id' => __('tasks.errors.assignee_invalid')]);
        }

        return $userId;
    }

    /**
     * Facturable por defecto: el del tipo; en un proyecto interno, nunca (SPEC §7).
     */
    private function defaultBillable(Project $project, ?TaskType $type): bool
    {
        if ($project->isInternal()) {
            return false;
        }

        return $type !== null ? $type->is_billable_default : true;
    }

    /**
     * @throws ValidationException
     */
    private function assertDates(?CarbonImmutable $start, ?CarbonImmutable $due): void
    {
        if ($start !== null && $due !== null && $due->lt($start)) {
            throw ValidationException::withMessages(['due_date' => __('tasks.errors.due_before_start')]);
        }
    }

    private function dateOrNull(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value->startOfDay();
        }

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->startOfDay() : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
