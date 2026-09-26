<?php

namespace App\Domain\Templates;

use App\Domain\Schedule\DependencyService;
use App\Domain\Tasks\TaskWriter;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\Task;
use App\Models\TaskType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Plantillas de proyecto (SPEC §4.3 y §6, D-055):
 * - apply(): crea en un proyecto las tareas, subtareas, hitos y dependencias de la plantilla, con
 *   fechas relativas al inicio indicado (inicio = inicio + start_offset_days; entrega = inicio +
 *   duration_days − 1; un hito solo lleva entrega). En proyectos de bolsas, todas van a la bolsa
 *   indicada (las subtareas, siempre a la de su padre).
 * - capture(): guarda la estructura de un proyecto existente como plantilla (sin horas, personas
 *   ni estados: solo títulos, tipos, estimaciones, fechas relativas y dependencias).
 */
final class ProjectTemplateService
{
    public const int MAX_TASKS = 500;

    public function __construct(
        private readonly TaskWriter $writer,
        private readonly DependencyService $dependencies,
    ) {}

    /**
     * @return list<Task> tareas creadas
     *
     * @throws ValidationException
     */
    public function apply(ProjectTemplate $template, Project $project, CarbonImmutable $start, User $actor, ?HourBank $bank = null): array
    {
        $structure = self::normalize($template->structure);
        $activeTypes = TaskType::query()->active()->pluck('id')->all();

        return DB::transaction(function () use ($structure, $project, $start, $actor, $bank, $activeTypes): array {
            $created = [];
            $byRef = [];

            // Primero las de primer nivel, después las subtareas (necesitan a su padre).
            $ordered = [
                ...array_filter($structure['tasks'], fn (array $t): bool => $t['parent_ref'] === null),
                ...array_filter($structure['tasks'], fn (array $t): bool => $t['parent_ref'] !== null),
            ];

            foreach ($ordered as $item) {
                $taskStart = $start->addDays($item['start_offset_days']);
                $due = $taskStart->addDays(max($item['duration_days'], 1) - 1);

                $task = $this->writer->create($actor, $project, [
                    'title' => $item['title'],
                    'parent_task_id' => $item['parent_ref'] !== null ? ($byRef[$item['parent_ref']] ?? null)?->id : null,
                    'hour_bank_id' => $bank?->id,
                    'task_type_id' => in_array($item['task_type_id'], $activeTypes, true) ? $item['task_type_id'] : null,
                    'priority' => $item['priority'],
                    'estimated_minutes' => $item['estimated_minutes'],
                    'is_milestone' => $item['is_milestone'],
                    'start_date' => $item['is_milestone'] ? null : $taskStart->toDateString(),
                    'due_date' => $due->toDateString(),
                ]);

                $byRef[$item['ref']] = $task;
                $created[] = $task;
            }

            foreach ($structure['dependencies'] as $link) {
                if (isset($byRef[$link['from_ref']], $byRef[$link['to_ref']])) {
                    $this->dependencies->link($byRef[$link['from_ref']], $byRef[$link['to_ref']], $actor);
                }
            }

            return $created;
        });
    }

    /**
     * @throws ValidationException
     */
    public function capture(Project $project, string $name, ?string $description, User $actor): ProjectTemplate
    {
        $tasks = Task::query()->where('project_id', $project->id)->orderBy('parent_task_id')->orderBy('position')->orderBy('id')
            ->get(['id', 'parent_task_id', 'title', 'task_type_id', 'priority', 'estimated_minutes', 'is_milestone', 'start_date', 'due_date']);

        $base = $project->start_date !== null
            ? CarbonImmutable::parse($project->start_date->toDateString())
            : CarbonImmutable::parse((string) ($tasks->min(fn (Task $t) => $t->start_date?->toDateString() ?? $t->due_date?->toDateString()) ?? now()->toDateString()));

        $items = [];
        foreach ($tasks as $task) {
            $begin = $task->start_date ?? $task->due_date;
            $items[] = [
                'ref' => 't'.$task->id,
                'parent_ref' => $task->parent_task_id !== null ? 't'.$task->parent_task_id : null,
                'title' => $task->title,
                'task_type_id' => $task->task_type_id,
                'priority' => $task->priority->value,
                'estimated_minutes' => $task->is_milestone ? null : $task->estimated_minutes,
                'is_milestone' => $task->is_milestone,
                'start_offset_days' => $begin !== null ? max((int) $base->diffInDays(CarbonImmutable::parse($begin->toDateString()), false), 0) : 0,
                'duration_days' => $task->start_date !== null && $task->due_date !== null
                    ? (int) CarbonImmutable::parse($task->start_date->toDateString())->diffInDays(CarbonImmutable::parse($task->due_date->toDateString())) + 1
                    : 1,
            ];
        }

        $ids = $tasks->modelKeys();
        $links = [];
        foreach ($this->dependencies->projectDependencies($project->id) as [$from, $to]) {
            if (in_array($from, $ids, true) && in_array($to, $ids, true)) {
                $links[] = ['from_ref' => 't'.$from, 'to_ref' => 't'.$to];
            }
        }

        return ProjectTemplate::query()->create([
            'name' => $name,
            'description' => $description,
            'structure' => self::normalize(['tasks' => $items, 'dependencies' => $links]),
            'created_by' => $actor->id,
        ]);
    }

    /**
     * Valida y completa una estructura (también la que llega de la interfaz de plantillas).
     *
     * @param  array<mixed>  $structure
     * @return array{tasks: list<array{ref: string, parent_ref: string|null, title: string, task_type_id: int|null, priority: string,
     *     estimated_minutes: int|null, is_milestone: bool, start_offset_days: int, duration_days: int}>,
     *     dependencies: list<array{from_ref: string, to_ref: string}>}
     *
     * @throws ValidationException
     */
    public static function normalize(array $structure): array
    {
        $fail = fn (string $reason) => throw ValidationException::withMessages(['structure' => __('schedule.errors.template_invalid', ['reason' => $reason])]);
        $rawTasks = $structure['tasks'] ?? null;

        if (! is_array($rawTasks) || $rawTasks === [] || count($rawTasks) > self::MAX_TASKS) {
            $fail('necesita entre 1 y '.self::MAX_TASKS.' tareas');
        }

        $tasks = [];
        $refs = [];
        foreach ((array) $rawTasks as $raw) {
            $ref = is_array($raw) ? trim((string) ($raw['ref'] ?? '')) : '';
            $title = is_array($raw) ? trim((string) ($raw['title'] ?? '')) : '';
            if ($ref === '' || $title === '' || isset($refs[$ref]) || mb_strlen($title) > 255) {
                $fail('cada tarea necesita una referencia única y un título');
            }
            $refs[$ref] = true;
            /** @var array<string, mixed> $raw */
            $tasks[] = [
                'ref' => $ref,
                'parent_ref' => isset($raw['parent_ref']) && $raw['parent_ref'] !== '' ? (string) $raw['parent_ref'] : null,
                'title' => $title,
                'task_type_id' => isset($raw['task_type_id']) && is_numeric($raw['task_type_id']) ? (int) $raw['task_type_id'] : null,
                'priority' => in_array($raw['priority'] ?? null, ['low', 'normal', 'high', 'urgent'], true) ? (string) $raw['priority'] : 'normal',
                'estimated_minutes' => isset($raw['estimated_minutes']) && is_numeric($raw['estimated_minutes']) ? max((int) $raw['estimated_minutes'], 0) : null,
                'is_milestone' => (bool) ($raw['is_milestone'] ?? false),
                'start_offset_days' => min(max((int) ($raw['start_offset_days'] ?? 0), 0), 3650),
                'duration_days' => min(max((int) ($raw['duration_days'] ?? 1), 1), 3650),
            ];
        }

        foreach ($tasks as $task) {
            if ($task['parent_ref'] !== null) {
                $parent = collect($tasks)->firstWhere('ref', $task['parent_ref']);
                if ($parent === null || $parent['parent_ref'] !== null) {
                    $fail('las subtareas deben colgar de una tarea de primer nivel de la plantilla');
                }
            }
        }

        $dependencies = [];
        foreach ((array) ($structure['dependencies'] ?? []) as $link) {
            if (! is_array($link) || ! isset($refs[(string) ($link['from_ref'] ?? '')], $refs[(string) ($link['to_ref'] ?? '')]) || ($link['from_ref'] ?? null) === ($link['to_ref'] ?? null)) {
                $fail('hay dependencias con referencias que no existen');
            }
            $dependencies[] = ['from_ref' => (string) $link['from_ref'], 'to_ref' => (string) $link['to_ref']];
        }

        return ['tasks' => $tasks, 'dependencies' => $dependencies];
    }
}
