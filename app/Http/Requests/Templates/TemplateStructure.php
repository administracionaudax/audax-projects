<?php

namespace App\Http\Requests\Templates;

use App\Domain\Templates\ProjectTemplateService;
use App\Enums\TaskPriority;
use App\Models\TaskType;
use App\Support\Duration;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validación de la estructura de una plantilla (D-058) con los errores junto a cada campo del
 * editor (`structure.tasks.3.title`, `structure.tasks.3.depends_on`…), que usan el editor de
 * /admin/plantillas y la importación en JSON. Lo que no se puede expresar con reglas (subtareas de
 * un solo nivel, dependencias que existen y sin ciclos) se comprueba en check(); al final,
 * ProjectTemplateService::normalize() da la estructura que se guarda.
 */
final class TemplateStructure
{
    /** Límite de días del inicio relativo y de la duración (unos diez años). */
    public const int MAX_DAYS = 3650;

    /** Dependencias como mucho (la plantilla más grande con varias por tarea). */
    public const int MAX_DEPENDENCIES = 5000;

    /** Estimación máxima de una tarea: 999 h, como en el panel de la tarea (TaskFieldRules). */
    public const int MAX_ESTIMATE_MINUTES = 999 * 60;

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'structure' => ['required', 'array'],
            'structure.tasks' => ['required', 'array', 'min:1', 'max:'.ProjectTemplateService::MAX_TASKS],
            'structure.tasks.*' => ['required', 'array'],
            'structure.tasks.*.ref' => ['required', 'string', 'max:40', 'distinct'],
            'structure.tasks.*.parent_ref' => ['nullable', 'string', 'max:40'],
            'structure.tasks.*.title' => ['required', 'string', 'max:255'],
            'structure.tasks.*.task_type_id' => ['nullable', 'integer'],
            'structure.tasks.*.priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'structure.tasks.*.estimated_minutes' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_ESTIMATE_MINUTES],
            'structure.tasks.*.is_milestone' => ['nullable', 'boolean'],
            // Sin inicio, el día 0; sin duración, un día (como ProjectTemplateService::normalize).
            'structure.tasks.*.start_offset_days' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_DAYS],
            'structure.tasks.*.duration_days' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_DAYS],
            'structure.dependencies' => ['nullable', 'array', 'max:'.self::MAX_DEPENDENCIES],
            'structure.dependencies.*' => ['required', 'array'],
            'structure.dependencies.*.from_ref' => ['required', 'string', 'max:40'],
            'structure.dependencies.*.to_ref' => ['required', 'string', 'max:40'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        $estimate = self::text('templates.errors.estimate_range', ['max' => Duration::format(self::MAX_ESTIMATE_MINUTES)]);
        $offset = self::text('templates.errors.offset_range', ['max' => self::MAX_DAYS]);
        $duration = self::text('templates.errors.duration_range', ['max' => self::MAX_DAYS]);
        $count = self::text('templates.errors.tasks_count', ['max' => ProjectTemplateService::MAX_TASKS]);

        return [
            'structure.required' => $count,
            'structure.tasks.required' => $count,
            'structure.tasks.min' => $count,
            'structure.tasks.max' => $count,
            'structure.tasks.*.ref.required' => self::text('templates.errors.task_ref'),
            'structure.tasks.*.ref.distinct' => self::text('templates.errors.task_ref'),
            'structure.tasks.*.title.required' => self::text('templates.errors.task_title'),
            'structure.tasks.*.estimated_minutes.min' => $estimate,
            'structure.tasks.*.estimated_minutes.max' => $estimate,
            'structure.tasks.*.start_offset_days.min' => $offset,
            'structure.tasks.*.start_offset_days.max' => $offset,
            'structure.tasks.*.duration_days.min' => $duration,
            'structure.tasks.*.duration_days.max' => $duration,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'structure.tasks.*.title' => self::text('templates.attributes.title'),
            'structure.tasks.*.task_type_id' => self::text('templates.attributes.task_type_id'),
            'structure.tasks.*.priority' => self::text('templates.attributes.priority'),
            'structure.tasks.*.estimated_minutes' => self::text('templates.attributes.estimated_minutes'),
        ];
    }

    /**
     * Reglas entre filas, con el error en la fila que hay que corregir. Solo si lo básico es válido.
     */
    public static function check(Validator $validator, mixed $structure): void
    {
        if ($validator->errors()->isNotEmpty() || ! is_array($structure)) {
            return;
        }

        /** @var list<array<string, mixed>> $tasks */
        $tasks = array_values((array) ($structure['tasks'] ?? []));
        /** @var list<array<string, mixed>> $links */
        $links = array_values((array) ($structure['dependencies'] ?? []));

        $index = [];
        foreach ($tasks as $i => $task) {
            $index[(string) $task['ref']] = $i;
        }

        // Tipos: uno activo (una sola consulta para toda la plantilla).
        $typeIds = array_values(array_unique(array_filter(array_map(
            fn (array $task): ?int => isset($task['task_type_id']) && is_numeric($task['task_type_id']) ? (int) $task['task_type_id'] : null,
            $tasks,
        ), fn (?int $id): bool => $id !== null)));
        $activeTypes = $typeIds === [] ? [] : TaskType::query()->active()->whereIn('id', $typeIds)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        foreach ($tasks as $i => $task) {
            if (isset($task['task_type_id']) && is_numeric($task['task_type_id']) && ! in_array((int) $task['task_type_id'], $activeTypes, true)) {
                $validator->errors()->add("structure.tasks.{$i}.task_type_id", self::text('templates.errors.type_invalid'));
            }

            $parent = isset($task['parent_ref']) && $task['parent_ref'] !== '' ? (string) $task['parent_ref'] : null;
            if ($parent === null) {
                continue;
            }

            if ($parent === (string) $task['ref']) {
                $validator->errors()->add("structure.tasks.{$i}.parent_ref", self::text('templates.errors.parent_self'));
            } elseif (! isset($index[$parent])) {
                $validator->errors()->add("structure.tasks.{$i}.parent_ref", self::text('templates.errors.parent_missing'));
            } elseif (($tasks[$index[$parent]]['parent_ref'] ?? null) !== null && $tasks[$index[$parent]]['parent_ref'] !== '') {
                $validator->errors()->add("structure.tasks.{$i}.parent_ref", self::text('templates.errors.parent_nested'));
            }
        }

        $valid = [];
        foreach ($links as $j => $link) {
            $from = (string) $link['from_ref'];
            $to = (string) $link['to_ref'];
            // El error va en la fila de la sucesora («depende de…»); si no existe, en la dependencia.
            $field = isset($index[$to]) ? "structure.tasks.{$index[$to]}.depends_on" : "structure.dependencies.{$j}.to_ref";

            if ($from === $to) {
                $validator->errors()->add($field, self::text('templates.errors.dependency_self'));
            } elseif (! isset($index[$from], $index[$to])) {
                $validator->errors()->add($field, self::text('templates.errors.dependency_missing'));
            } else {
                $valid[] = ['from_ref' => $from, 'to_ref' => $to];
            }
        }

        $cycle = ProjectTemplateService::findCycle($valid);
        foreach ($cycle ?? [] as $ref) {
            $validator->errors()->add("structure.tasks.{$index[$ref]}.depends_on", self::text('templates.errors.dependency_cycle'));
        }
    }

    /**
     * Estructura que se guarda: un hito no lleva estimación y dura un día; después, normalize().
     *
     * @param  array<mixed>  $structure
     * @return array{tasks: list<array{ref: string, parent_ref: string|null, title: string, task_type_id: int|null, priority: string,
     *     estimated_minutes: int|null, is_milestone: bool, start_offset_days: int, duration_days: int}>,
     *     dependencies: list<array{from_ref: string, to_ref: string}>}
     */
    public static function clean(array $structure): array
    {
        $normalized = ProjectTemplateService::normalize($structure);

        foreach ($normalized['tasks'] as $i => $task) {
            if ($task['is_milestone']) {
                $normalized['tasks'][$i]['estimated_minutes'] = null;
                $normalized['tasks'][$i]['duration_days'] = 1;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }
}
