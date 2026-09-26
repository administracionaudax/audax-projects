<?php

namespace App\Http\Requests\Workload;

use App\Http\Requests\Tasks\TaskFieldRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

/**
 * Reasignar y replanificar una tarea desde la vista Carga (panel de una celda y bandejas): solo el
 * responsable, las fechas y la estimación, con las mismas reglas de forma que el panel de Tareas.
 * Cambios parciales (solo los campos enviados). Autoriza el controlador (TaskPolicy::update y el
 * alcance de la vista, D-052); las reglas del dominio las aplica TaskWriter.
 */
class UpdateWorkloadTaskRequest extends FormRequest
{
    use TaskFieldRules;

    public const array FIELDS = ['assignee_user_id', 'start_date', 'due_date', 'estimated_minutes'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return Arr::only($this->taskFieldRules(partial: true), self::FIELDS);
    }
}
