<?php

namespace App\Domain\Templates;

use App\Http\Requests\Templates\TemplateStructure;
use App\Models\ProjectTemplate;
use App\Models\TaskType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;

/**
 * Exportar e importar plantillas en JSON (D-058, para copias). El fichero lleva el nombre, la
 * descripción y la estructura; cada tarea lleva además el nombre de su tipo (`task_type`), para
 * que al importar en otra instalación, con otros ids, se encuentre el tipo por su nombre.
 * Al importar se valida igual que en el editor.
 */
final class TemplateTransfer
{
    public const string FORMAT = 'audax-project-template';

    public const int VERSION = 1;

    /**
     * @return array{format: string, version: int, name: string, description: string|null, structure: array<string, mixed>}
     */
    public function export(ProjectTemplate $template): array
    {
        $structure = ProjectTemplateService::normalize($template->structure);
        $typeIds = array_values(array_unique(array_filter(array_column($structure['tasks'], 'task_type_id'))));
        $names = $typeIds === [] ? collect() : TaskType::query()->withTrashed()->whereIn('id', $typeIds)->pluck('name', 'id');

        $tasks = [];
        foreach ($structure['tasks'] as $task) {
            $tasks[] = [
                ...$task,
                'task_type' => $task['task_type_id'] !== null ? $names->get($task['task_type_id']) : null,
            ];
        }

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'name' => $template->name,
            'description' => $template->description,
            'structure' => ['tasks' => $tasks, 'dependencies' => $structure['dependencies']],
        ];
    }

    /**
     * Lee un fichero exportado (o una estructura suelta {tasks, dependencies}) y la valida.
     *
     * @return array{name: string, description: string|null, structure: array<string, mixed>}
     *
     * @throws ValidationException con el error en `file`
     */
    public function parse(string $contents, string $fallbackName): array
    {
        try {
            $data = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['file' => $this->text('templates.errors.import_json')]);
        }

        if (! is_array($data)) {
            throw ValidationException::withMessages(['file' => $this->text('templates.errors.import_json')]);
        }

        $structure = isset($data['structure']) && is_array($data['structure']) ? $data['structure'] : $data;
        $structure = TemplateStructure::trimRefs($this->resolveTypes($structure));

        $validator = Validator::make(['structure' => $structure], TemplateStructure::rules(), TemplateStructure::messages(), TemplateStructure::attributes());
        $validator->after(fn ($after) => TemplateStructure::check($after, $structure));

        if ($validator->fails()) {
            $key = (string) array_key_first($validator->errors()->messages());
            $message = (string) $validator->errors()->first();
            // «tarea 3: Escribe el título de la tarea.»: el número de fila ayuda a encontrarla.
            if (preg_match('/^structure\.(tasks|dependencies)\.(\d+)\./', $key, $match) === 1) {
                $message = $this->text($match[1] === 'tasks' ? 'templates.import.row' : 'templates.import.dependency_row', [
                    'row' => (int) $match[2] + 1,
                    'message' => $message,
                ]);
            }

            throw $this->invalid($message);
        }

        // Red de seguridad: lo que normalize() rechace tras la validación también va a `file`, que
        // es lo que enseña el diálogo de importar.
        try {
            $clean = TemplateStructure::clean($structure);
        } catch (ValidationException $exception) {
            throw $this->invalid(ProjectFromTemplate::firstMessage($exception));
        }

        $name = isset($data['name']) && is_string($data['name']) && trim($data['name']) !== '' ? trim($data['name']) : $fallbackName;
        $description = isset($data['description']) && is_string($data['description']) && trim($data['description']) !== '' ? trim($data['description']) : null;

        return [
            'name' => mb_substr($name, 0, 255),
            'description' => $description !== null ? mb_substr($description, 0, 2000) : null,
            'structure' => $clean,
        ];
    }

    private function invalid(string $reason): ValidationException
    {
        return ValidationException::withMessages(['file' => $this->text('templates.errors.import_invalid', ['reason' => $reason])]);
    }

    /**
     * Tipo de cada tarea: el del nombre (`task_type`) si hay un tipo activo que se llame así; si no,
     * el id si es de un tipo activo; si no, sin tipo.
     *
     * @param  array<mixed>  $structure
     * @return array<mixed>
     */
    private function resolveTypes(array $structure): array
    {
        if (! isset($structure['tasks']) || ! is_array($structure['tasks'])) {
            return $structure;
        }

        $types = TaskType::query()->active()->get(['id', 'name']);
        $byName = $types->mapWithKeys(fn (TaskType $type): array => [mb_strtolower(trim($type->name)) => $type->id]);
        $ids = $types->pluck('id')->map(fn ($id): int => (int) $id)->all();

        foreach ($structure['tasks'] as $i => $task) {
            if (! is_array($task)) {
                continue;
            }

            $name = isset($task['task_type']) && is_string($task['task_type']) ? mb_strtolower(trim($task['task_type'])) : null;
            $id = isset($task['task_type_id']) && is_numeric($task['task_type_id']) ? (int) $task['task_type_id'] : null;

            $task['task_type_id'] = match (true) {
                $name !== null && $byName->has($name) => $byName->get($name),
                $id !== null && in_array($id, $ids, true) => $id,
                default => null,
            };
            unset($task['task_type']);
            $structure['tasks'][$i] = $task;
        }

        return $structure;
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    private function text(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }
}
