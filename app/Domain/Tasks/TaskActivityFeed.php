<?php

namespace App\Domain\Tasks;

use App\Enums\TaskPriority;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TaskType;
use App\Models\User;
use App\Support\Duration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Actividad de una tarea para el panel (SPEC §4.6): quién cambió qué, antes y después, y cuándo.
 * Sale del registro de auditoría (spatie/activitylog, trait LogsDomainActivity). Los ids se
 * traducen a nombres con una consulta por tipo (sin N+1) y los valores se formatean en español.
 */
final class TaskActivityFeed
{
    public const int LIMIT = 50;

    /**
     * Campos que se muestran (el orden es el del panel). La descripción solo indica que cambió.
     */
    public const array FIELDS = [
        'title', 'status_id', 'assignee_user_id', 'priority', 'hour_bank_id', 'task_type_id',
        'start_date', 'due_date', 'estimated_minutes', 'is_billable', 'is_milestone',
        'project_id', 'description',
    ];

    private const array REFERENCES = [
        'status_id' => 'statuses',
        'assignee_user_id' => 'users',
        'hour_bank_id' => 'banks',
        'task_type_id' => 'types',
        'project_id' => 'projects',
    ];

    /**
     * @return list<array{id: int, event: string, causer: string|null, created_at: string|null, changes: list<array{field: string, from: string|null, to: string|null}>}>
     */
    public function for(Task $task): array
    {
        /** @var Collection<int, Activity> $activities */
        $activities = $task->activitiesAsSubject()
            ->with('causer')
            ->latest('id')
            ->limit(self::LIMIT)
            ->get();

        $names = $this->names($activities);

        return array_values($activities->map(function (Activity $activity) use ($names): array {
            $changes = $activity->attribute_changes?->toArray() ?? [];
            /** @var array<string, mixed> $new */
            $new = (array) ($changes['attributes'] ?? []);
            /** @var array<string, mixed> $old */
            $old = (array) ($changes['old'] ?? []);
            $causer = $activity->causer;

            return [
                'id' => $activity->id,
                'event' => (string) ($activity->event ?? $activity->description),
                'causer' => $causer instanceof User ? $causer->name : null,
                'created_at' => $activity->created_at?->toIso8601ZuluString(),
                'changes' => $activity->event === 'updated' ? $this->changes($new, $old, $names) : [],
            ];
        })->all());
    }

    /**
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $old
     * @param  array<string, array<int, string>>  $names
     * @return list<array{field: string, from: string|null, to: string|null}>
     */
    private function changes(array $new, array $old, array $names): array
    {
        $changes = [];

        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $new) && ! array_key_exists($field, $old)) {
                continue;
            }

            if ($field === 'description') {
                $changes[] = ['field' => $field, 'from' => null, 'to' => null];

                continue;
            }

            $changes[] = [
                'field' => $field,
                'from' => $this->display($field, $old[$field] ?? null, $names),
                'to' => $this->display($field, $new[$field] ?? null, $names),
            ];
        }

        return $changes;
    }

    /**
     * @param  array<string, array<int, string>>  $names
     */
    private function display(string $field, mixed $value, array $names): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (isset(self::REFERENCES[$field])) {
            return $names[self::REFERENCES[$field]][(int) $value] ?? $this->text('tasks.activity.deleted_value');
        }

        return match ($field) {
            'priority' => TaskPriority::tryFrom((string) $value)?->label() ?? (string) $value,
            'start_date', 'due_date' => is_string($value) ? CarbonImmutable::parse($value)->format('d/m/Y') : null,
            'estimated_minutes' => Duration::format((int) $value),
            'is_billable', 'is_milestone' => $this->text($value ? 'tasks.activity.yes' : 'tasks.activity.no'),
            default => is_scalar($value) ? (string) $value : null,
        };
    }

    private function text(string $key): string
    {
        $text = __($key);

        return is_string($text) ? $text : $key;
    }

    /**
     * Nombres de estados, personas, bolsas, tipos y proyectos citados en la actividad.
     *
     * @param  Collection<int, Activity>  $activities
     * @return array<string, array<int, string>>
     */
    private function names(Collection $activities): array
    {
        $ids = array_fill_keys(array_values(self::REFERENCES), []);

        foreach ($activities as $activity) {
            $changes = $activity->attribute_changes?->toArray() ?? [];

            foreach (['attributes', 'old'] as $side) {
                foreach ((array) ($changes[$side] ?? []) as $field => $value) {
                    if (isset(self::REFERENCES[$field]) && is_numeric($value)) {
                        $ids[self::REFERENCES[$field]][] = (int) $value;
                    }
                }
            }
        }

        return [
            'statuses' => $this->pluck(TaskStatus::query()->whereKey(array_unique($ids['statuses']))->pluck('name', 'id')),
            'users' => $this->pluck(User::query()->whereKey(array_unique($ids['users']))->pluck('name', 'id')),
            'banks' => $this->pluck(HourBank::query()->withTrashed()->whereKey(array_unique($ids['banks']))->pluck('name', 'id')),
            'types' => $this->pluck(TaskType::query()->withTrashed()->whereKey(array_unique($ids['types']))->pluck('name', 'id')),
            'projects' => $this->pluck(Project::query()->withTrashed()->whereKey(array_unique($ids['projects']))->pluck('name', 'id')),
        ];
    }

    /**
     * @param  Collection<array-key, mixed>  $values
     * @return array<int, string>
     */
    private function pluck(Collection $values): array
    {
        $names = [];

        foreach ($values as $id => $name) {
            $names[(int) $id] = (string) $name;
        }

        return $names;
    }
}
