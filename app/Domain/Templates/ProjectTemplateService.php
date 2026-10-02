<?php

namespace App\Domain\Templates;

use App\Domain\Schedule\DependencyService;
use App\Domain\Tasks\TaskWriter;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\TaskType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Plantillas de proyecto (SPEC §4.3 y §6, D-058):
 * - apply(): crea en un proyecto las tareas, subtareas, hitos y dependencias de la plantilla, con
 *   fechas relativas al inicio indicado (inicio = inicio + start_offset_days; entrega = inicio +
 *   duration_days − 1; un hito solo lleva entrega). En proyectos de bolsas, todas van a la bolsa
 *   indicada (las subtareas, siempre a la de su padre).
 * - capture(): guarda la estructura de un proyecto existente como plantilla (sin horas, personas
 *   ni estados: solo títulos, tipos, estimaciones, fechas relativas y dependencias). Si alguna
 *   tarea empezaría o duraría más de MAX_DAYS días (una fecha mal escrita), no guarda nada y dice
 *   cuáles son las fechas extremas.
 */
final class ProjectTemplateService
{
    public const int MAX_TASKS = 500;

    /** Límite de días del inicio relativo y de la duración de cada tarea (unos diez años). */
    public const int MAX_DAYS = 3650;

    /** Dependencias por INSERT al aplicar una plantilla. */
    private const int DEPENDENCY_CHUNK = 150;

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

            $this->insertDependencies($structure['dependencies'], $byRef, $actor);

            return $created;
        });
    }

    /**
     * Dependencias de la plantilla entre las tareas recién creadas, insertadas por lotes. Sin pasar
     * por DependencyService::link(), que por cada dependencia relee todas las del proyecto para
     * buscar ciclos (O(D²): minutos con una plantilla grande): normalize() ya garantiza que las de
     * la plantilla no se repiten ni forman ciclos, y solo unen tareas nuevas del mismo proyecto, así
     * que tampoco pueden cerrar un ciclo con las que ya había (D-056).
     *
     * @param  list<array{from_ref: string, to_ref: string}>  $links
     * @param  array<string, Task>  $byRef
     */
    private function insertDependencies(array $links, array $byRef, User $actor): void
    {
        $now = (new TaskDependency)->freshTimestampString();
        $rows = [];

        foreach ($links as $link) {
            if (isset($byRef[$link['from_ref']], $byRef[$link['to_ref']])) {
                $rows[] = [
                    'predecessor_task_id' => $byRef[$link['from_ref']]->id,
                    'successor_task_id' => $byRef[$link['to_ref']]->id,
                    'type' => TaskDependency::FINISH_TO_START,
                    'created_by' => $actor->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // 6 valores por fila: 150 filas caben en el límite de parámetros de cualquier motor.
        foreach (array_chunk($rows, self::DEPENDENCY_CHUNK) as $chunk) {
            TaskDependency::query()->insert($chunk);
        }
    }

    /**
     * No guarda nada si el proyecto no tiene tareas, si tiene demasiadas o si alguna tarea
     * empezaría o duraría más de MAX_DAYS días (una fecha mal escrita).
     *
     * @throws ValidationException
     */
    public function capture(Project $project, string $name, ?string $description, User $actor): ProjectTemplate
    {
        $tasks = Task::query()->where('project_id', $project->id)->orderBy('parent_task_id')->orderBy('position')->orderBy('id')
            ->get(['id', 'parent_task_id', 'title', 'task_type_id', 'priority', 'estimated_minutes', 'is_milestone', 'start_date', 'due_date']);

        // Día 0: el inicio del proyecto o, si alguna tarea empieza antes, esa tarea (así ningún día
        // relativo queda negativo y las tareas anteriores al inicio conservan su orden y separación).
        // De la primera y la última fecha se guarda de dónde salen, por si hay que explicar un error.
        $first = ['date' => $project->start_date?->toDateString(), 'task' => null];
        $last = null;
        foreach ($tasks as $task) {
            $dates = array_values(array_filter([$task->start_date?->toDateString(), $task->due_date?->toDateString()]));
            if ($dates === []) {
                continue;
            }
            if ($first['date'] === null || min($dates) < $first['date']) {
                $first = ['date' => min($dates), 'task' => $task->title];
            }
            if ($last === null || max($dates) > $last['date']) {
                $last = ['date' => max($dates), 'task' => $task->title];
            }
        }
        $base = CarbonImmutable::parse($first['date'] ?? now()->toDateString());

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

        // Una fecha mal escrita (el año 0026 en lugar de 2026) no puede estropear toda la plantilla:
        // normalize() recortaría en silencio al día MAX_DAYS el inicio o la duración de las demás.
        $tooFar = fn (array $item): bool => $item['start_offset_days'] > self::MAX_DAYS || $item['duration_days'] > self::MAX_DAYS;
        if ($last !== null && array_filter($items, $tooFar) !== []) {
            throw ValidationException::withMessages(['structure' => self::spanError($base, $first['task'], $last)]);
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
     * «No se puede guardar como plantilla: sus fechas van del 01/10/0026 («Briefing») al 10/10/2026
     * («Entrega»), más de 3650 días…»: las dos fechas extremas y de dónde sale cada una (el inicio
     * del proyecto o una tarea), que es donde está la fecha mal escrita.
     *
     * @param  string|null  $firstTask  tarea de la primera fecha, o null si es el inicio del proyecto
     * @param  array{date: string, task: string}  $last  última fecha y su tarea
     */
    private static function spanError(CarbonImmutable $base, ?string $firstTask, array $last): string
    {
        $source = fn (?string $task): string => $task === null
            ? self::text('templates.errors.capture_span_project')
            : self::text('templates.errors.capture_span_task', ['title' => $task]);

        return self::text('templates.errors.capture_span', [
            'from' => $base->format('d/m/Y'),
            'from_name' => $source($firstTask),
            'to' => CarbonImmutable::parse($last['date'])->format('d/m/Y'),
            'to_name' => $source($last['task']),
            'max' => self::MAX_DAYS,
        ]);
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
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
        // Los motivos, en lang/es/templates.php (templates.errors.*).
        $fail = fn (string $reason, array $replace = []) => throw ValidationException::withMessages([
            'structure' => self::text("templates.errors.{$reason}", $replace),
        ]);
        $rawTasks = $structure['tasks'] ?? null;

        if (! is_array($rawTasks) || $rawTasks === [] || count($rawTasks) > self::MAX_TASKS) {
            $fail('tasks_count', ['max' => self::MAX_TASKS]);
        }

        $tasks = [];
        $refs = [];
        foreach ((array) $rawTasks as $raw) {
            $ref = is_array($raw) ? trim((string) ($raw['ref'] ?? '')) : '';
            $title = is_array($raw) ? trim((string) ($raw['title'] ?? '')) : '';
            if ($ref === '' || $title === '' || isset($refs[$ref]) || mb_strlen($title) > 255) {
                $fail('structure_task');
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
                'start_offset_days' => min(max((int) ($raw['start_offset_days'] ?? 0), 0), self::MAX_DAYS),
                'duration_days' => min(max((int) ($raw['duration_days'] ?? 1), 1), self::MAX_DAYS),
            ];
        }

        foreach ($tasks as $task) {
            if ($task['parent_ref'] !== null) {
                $parent = collect($tasks)->firstWhere('ref', $task['parent_ref']);
                if ($parent === null || $parent['parent_ref'] !== null) {
                    $fail('structure_parent');
                }
            }
        }

        $dependencies = [];
        $seen = [];
        foreach ((array) ($structure['dependencies'] ?? []) as $link) {
            if (! is_array($link) || ! isset($refs[(string) ($link['from_ref'] ?? '')], $refs[(string) ($link['to_ref'] ?? '')]) || ($link['from_ref'] ?? null) === ($link['to_ref'] ?? null)) {
                $fail('structure_dependency');
            }

            // Enlazar dos veces lo mismo no duplica (D-056).
            $key = $link['from_ref']."\n".$link['to_ref'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $dependencies[] = ['from_ref' => (string) $link['from_ref'], 'to_ref' => (string) $link['to_ref']];
        }

        if (self::findCycle($dependencies) !== null) {
            $fail('structure_cycle');
        }

        return ['tasks' => $tasks, 'dependencies' => $dependencies];
    }

    /**
     * Primer ciclo de las dependencias de una plantilla (D-058: sin ciclos), como la lista de
     * referencias que lo forman, o null si no hay ninguno. Búsqueda en profundidad en memoria.
     *
     * @param  list<array{from_ref: string, to_ref: string}>  $dependencies
     * @return list<string>|null
     */
    public static function findCycle(array $dependencies): ?array
    {
        $edges = [];
        foreach ($dependencies as $link) {
            $edges[$link['from_ref']][] = $link['to_ref'];
        }

        // 0 = sin visitar, 1 = en el camino actual, 2 = terminada.
        $state = [];

        foreach (array_keys($edges) as $ref) {
            if (($state[$ref] ?? 0) === 0) {
                $path = [];
                $cycle = self::visit((string) $ref, $edges, $state, $path);
                if ($cycle !== null) {
                    return $cycle;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, list<string>>  $edges
     * @param  array<string, int>  $state
     * @param  list<string>  $path
     * @return list<string>|null
     */
    private static function visit(string $ref, array $edges, array &$state, array &$path): ?array
    {
        $state[$ref] = 1;
        $path[] = $ref;

        foreach ($edges[$ref] ?? [] as $next) {
            if (($state[$next] ?? 0) === 1) {
                $start = array_search($next, $path, true);

                return array_slice($path, $start === false ? 0 : $start);
            }

            if (($state[$next] ?? 0) === 0) {
                $cycle = self::visit($next, $edges, $state, $path);
                if ($cycle !== null) {
                    return $cycle;
                }
            }
        }

        array_pop($path);
        $state[$ref] = 2;

        return null;
    }

    /**
     * Cifras de una estructura para los listados y los selectores: tareas, subtareas, hitos,
     * dependencias y duración total en días (del día 0 a la entrega más tardía).
     *
     * @param  array<mixed>  $structure
     * @return array{tasks: int, subtasks: int, milestones: int, dependencies: int, duration_days: int}
     */
    public static function stats(array $structure): array
    {
        $tasks = is_array($structure['tasks'] ?? null) ? $structure['tasks'] : [];
        $subtasks = 0;
        $milestones = 0;
        $end = 0;

        foreach ($tasks as $task) {
            if (! is_array($task)) {
                continue;
            }
            if (($task['parent_ref'] ?? null) !== null && $task['parent_ref'] !== '') {
                $subtasks++;
            }
            if ((bool) ($task['is_milestone'] ?? false)) {
                $milestones++;
            }
            $end = max($end, (int) ($task['start_offset_days'] ?? 0) + max((int) ($task['duration_days'] ?? 1), 1));
        }

        return [
            'tasks' => count($tasks),
            'subtasks' => $subtasks,
            'milestones' => $milestones,
            'dependencies' => is_array($structure['dependencies'] ?? null) ? count($structure['dependencies']) : 0,
            'duration_days' => $end,
        ];
    }
}
