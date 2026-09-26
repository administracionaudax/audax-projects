<?php

namespace App\Domain\Projects;

use App\Enums\HourBankStatus;
use App\Enums\ProjectStatus;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Support\Duration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;

/**
 * Actividad reciente del resumen del proyecto (SPEC §6): la auditoría (spatie activity_log) del
 * proyecto, sus bolsas y sus tareas, en español legible (quién, qué y cuándo).
 *
 * - Nunca muestra valores económicos; sin view-financials, ni siquiera que cambiaron.
 * - Se omiten los cambios sin interés para el equipo (orden manual, marcas internas).
 */
final class ProjectActivityFeed
{
    /** Columnas que no se cuentan como cambio visible. */
    private const array IGNORED = [
        'id', 'created_at', 'updated_at', 'deleted_at', 'position', 'completed_at', 'created_by',
        'closed_at', 'closed_by', 'closed_remaining_minutes', 'renewed_from_id', 'consumed_minutes',
        'overage_minutes', 'owner_user_id', 'status', 'status_id', 'assignee_user_id', 'hour_bank_id',
    ];

    /**
     * @return list<array{id: int, actor: array{id: int, name: string}|null, text: string, url: string|null, created_at: string|null}>
     */
    public function latest(Project $project, User $viewer, int $limit = 15): array
    {
        $projectType = $project->getMorphClass();
        $bankType = (new HourBank)->getMorphClass();
        $taskType = (new Task)->getMorphClass();

        $activities = Activity::query()
            ->where(function (Builder $where) use ($project, $projectType, $bankType, $taskType): void {
                $where->where(fn (Builder $q) => $q->where('subject_type', $projectType)->where('subject_id', $project->id))
                    ->orWhere(fn (Builder $q) => $q->where('subject_type', $bankType)->whereIn(
                        'subject_id',
                        HourBank::query()->withTrashed()->select('id')->where('project_id', $project->id),
                    ))
                    ->orWhere(fn (Builder $q) => $q->where('subject_type', $taskType)->whereIn(
                        'subject_id',
                        Task::query()->withTrashed()->select('id')->where('project_id', $project->id),
                    ));
            })
            ->latest('id')
            ->limit($limit * 3)
            ->get();

        $financials = Gate::forUser($viewer)->allows('view-financials');
        $context = $this->context($activities, $project);

        $items = [];

        foreach ($activities as $activity) {
            $item = $this->describe($activity, $context, $financials, $project, $projectType, $bankType, $taskType);

            if ($item !== null) {
                $items[] = $item;
            }

            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /**
     * Personas, bolsas, tareas y estados que nombran los textos, en pocas consultas.
     *
     * @param  Collection<int, Activity>  $activities
     * @return array{users: array<int, string>, banks: array<int, string>, tasks: array<int, string>, statuses: array<int, string>}
     */
    private function context(Collection $activities, Project $project): array
    {
        $userIds = [];
        $bankIds = [];
        $taskIds = [];
        $statusIds = [];

        foreach ($activities as $activity) {
            $changes = $this->changes($activity);

            if ($activity->causer_id !== null) {
                $userIds[] = (int) $activity->causer_id;
            }

            foreach (['owner_user_id', 'assignee_user_id'] as $field) {
                if (isset($changes['attributes'][$field]) && is_numeric($changes['attributes'][$field])) {
                    $userIds[] = (int) $changes['attributes'][$field];
                }
            }

            if (isset($changes['attributes']['status_id']) && is_numeric($changes['attributes']['status_id'])) {
                $statusIds[] = (int) $changes['attributes']['status_id'];
            }

            if ($activity->subject_type === (new HourBank)->getMorphClass()) {
                $bankIds[] = (int) $activity->subject_id;
            } elseif ($activity->subject_type === (new Task)->getMorphClass()) {
                $taskIds[] = (int) $activity->subject_id;
            }
        }

        return [
            'users' => $this->names(User::query(), $userIds, 'name'),
            'banks' => $this->names(HourBank::query()->withTrashed()->where('project_id', $project->id), $bankIds, 'name'),
            'tasks' => $this->names(Task::query()->withTrashed()->where('project_id', $project->id), $taskIds, 'title'),
            'statuses' => $this->names(TaskStatus::query(), $statusIds, 'name'),
        ];
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function names(Builder $query, array $ids, string $column): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var array<int, string> */
        return $query->whereKey(array_values(array_unique($ids)))->pluck($column, 'id')->all();
    }

    /**
     * @param  array{users: array<int, string>, banks: array<int, string>, tasks: array<int, string>, statuses: array<int, string>}  $context
     * @return array{id: int, actor: array{id: int, name: string}|null, text: string, url: string|null, created_at: string|null}|null
     */
    private function describe(Activity $activity, array $context, bool $financials, Project $project, string $projectType, string $bankType, string $taskType): ?array
    {
        $changes = $this->changes($activity);
        $event = (string) $activity->event;
        $subjectId = (int) $activity->subject_id;

        $text = match ($activity->subject_type) {
            $projectType => $this->projectText($event, $changes, $activity, $context, $financials),
            $bankType => $this->bankText($event, $changes, $context['banks'][$subjectId] ?? $this->nameFrom($changes, 'name'), $financials),
            $taskType => $this->taskText($event, $changes, $context, $context['tasks'][$subjectId] ?? $this->nameFrom($changes, 'title')),
            default => null,
        };

        if ($text === null) {
            return null;
        }

        $url = match (true) {
            $event === 'deleted' => null,
            $activity->subject_type === $bankType && isset($context['banks'][$subjectId]) => route('projects.hour-banks.show', ['project' => $project->id, 'hourBank' => $subjectId], false),
            $activity->subject_type === $taskType && isset($context['tasks'][$subjectId]) => "/proyectos/{$project->id}/tareas?tarea={$subjectId}",
            default => null,
        };

        $causerId = $activity->causer_id !== null ? (int) $activity->causer_id : null;

        return [
            'id' => $activity->id,
            'actor' => $causerId !== null ? [
                'id' => $causerId,
                'name' => $context['users'][$causerId] ?? $this->trans('projects.activity.unknown_person'),
            ] : null,
            'text' => $text,
            'url' => $url,
            'created_at' => $activity->created_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @param  array{attributes: array<string, mixed>, old: array<string, mixed>}  $changes
     * @param  array{users: array<int, string>, banks: array<int, string>, tasks: array<int, string>, statuses: array<int, string>}  $context
     */
    private function projectText(string $event, array $changes, Activity $activity, array $context, bool $financials): ?string
    {
        $memberName = (string) ($activity->getProperty('user_name') ?? $this->trans('projects.activity.unknown_person'));

        return match ($event) {
            'created', 'deleted', 'restored' => $this->trans("projects.activity.project.{$event}"),
            'member_added', 'member_removed', 'manager_added', 'manager_removed' => $this->trans("projects.activity.project.{$event}", ['name' => $memberName]),
            'updated' => $this->projectUpdate($changes, $context, $financials),
            default => null,
        };
    }

    /**
     * @param  array{attributes: array<string, mixed>, old: array<string, mixed>}  $changes
     * @param  array{users: array<int, string>, banks: array<int, string>, tasks: array<int, string>, statuses: array<int, string>}  $context
     */
    private function projectUpdate(array $changes, array $context, bool $financials): ?string
    {
        $attributes = $changes['attributes'];

        if (array_key_exists('status', $attributes)) {
            $status = ProjectStatus::tryFrom((string) $attributes['status']);

            return $this->trans('projects.activity.project.status', ['status' => $status?->label() ?? (string) $attributes['status']]);
        }

        if (array_key_exists('owner_user_id', $attributes) && is_numeric($attributes['owner_user_id'])) {
            return $this->trans('projects.activity.project.owner', [
                'name' => $context['users'][(int) $attributes['owner_user_id']] ?? $this->trans('projects.activity.unknown_person'),
            ]);
        }

        $fields = $this->fields($attributes, Project::FINANCIAL_ATTRIBUTES, $financials);

        return $fields === null ? null : $this->trans('projects.activity.project.updated', ['fields' => $fields]);
    }

    /**
     * @param  array{attributes: array<string, mixed>, old: array<string, mixed>}  $changes
     */
    private function bankText(string $event, array $changes, string $bank, bool $financials): ?string
    {
        if (in_array($event, ['created', 'deleted', 'restored'], true)) {
            return $this->trans("projects.activity.hour_bank.{$event}", ['bank' => $bank]);
        }

        if ($event !== 'updated') {
            return null;
        }

        $attributes = $changes['attributes'];

        if (array_key_exists('status', $attributes)) {
            $status = HourBankStatus::tryFrom((string) $attributes['status']);

            return $this->trans('projects.activity.hour_bank.status', [
                'bank' => $bank,
                'status' => $status?->label() ?? (string) $attributes['status'],
            ]);
        }

        if (array_key_exists('total_minutes', $attributes) && is_numeric($attributes['total_minutes'])) {
            return $this->trans('projects.activity.hour_bank.total', [
                'bank' => $bank,
                'total' => Duration::format((int) $attributes['total_minutes']),
            ]);
        }

        $fields = $this->fields($attributes, HourBank::FINANCIAL_ATTRIBUTES, $financials);

        return $fields === null ? null : $this->trans('projects.activity.hour_bank.updated', ['bank' => $bank, 'fields' => $fields]);
    }

    /**
     * @param  array{attributes: array<string, mixed>, old: array<string, mixed>}  $changes
     * @param  array{users: array<int, string>, banks: array<int, string>, tasks: array<int, string>, statuses: array<int, string>}  $context
     */
    private function taskText(string $event, array $changes, array $context, string $task): ?string
    {
        if (in_array($event, ['created', 'deleted', 'restored'], true)) {
            return $this->trans("projects.activity.task.{$event}", ['task' => $task]);
        }

        if ($event !== 'updated') {
            return null;
        }

        $attributes = $changes['attributes'];

        if (array_key_exists('status_id', $attributes) && is_numeric($attributes['status_id'])) {
            return $this->trans('projects.activity.task.status', [
                'task' => $task,
                'status' => $context['statuses'][(int) $attributes['status_id']] ?? $this->trans('projects.activity.unknown_subject'),
            ]);
        }

        if (array_key_exists('assignee_user_id', $attributes)) {
            $assignee = $attributes['assignee_user_id'];

            return is_numeric($assignee)
                ? $this->trans('projects.activity.task.assigned', [
                    'task' => $task,
                    'name' => $context['users'][(int) $assignee] ?? $this->trans('projects.activity.unknown_person'),
                ])
                : $this->trans('projects.activity.task.unassigned', ['task' => $task]);
        }

        if (array_key_exists('hour_bank_id', $attributes)) {
            return $this->trans('projects.activity.task.bank', ['task' => $task]);
        }

        $fields = $this->fields($attributes, [], true);

        return $fields === null ? null : $this->trans('projects.activity.task.updated', ['task' => $task, 'fields' => $fields]);
    }

    /**
     * Lista legible de campos cambiados («nombre, fechas»), o null si no queda ninguno visible.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $financialFields
     */
    private function fields(array $attributes, array $financialFields, bool $financials): ?string
    {
        $labels = [];

        foreach (array_keys($attributes) as $field) {
            if (in_array($field, self::IGNORED, true)) {
                continue;
            }

            if (! $financials && in_array($field, $financialFields, true)) {
                continue;
            }

            $key = "projects.activity.fields.{$field}";
            $label = $this->trans($key);

            if ($label !== $key) {
                $labels[$label] = true;
            }
        }

        return $labels === [] ? null : implode(', ', array_keys($labels));
    }

    /**
     * @param  array{attributes: array<string, mixed>, old: array<string, mixed>}  $changes
     */
    private function nameFrom(array $changes, string $field): string
    {
        $value = $changes['attributes'][$field] ?? $changes['old'][$field] ?? null;

        return is_string($value) && $value !== '' ? $value : $this->trans('projects.activity.unknown_subject');
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    private function trans(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }

    /**
     * @return array{attributes: array<string, mixed>, old: array<string, mixed>}
     */
    private function changes(Activity $activity): array
    {
        $raw = $activity->attribute_changes?->toArray() ?? [];

        return [
            'attributes' => is_array($raw['attributes'] ?? null) ? $raw['attributes'] : [],
            'old' => is_array($raw['old'] ?? null) ? $raw['old'] : [],
        ];
    }
}
