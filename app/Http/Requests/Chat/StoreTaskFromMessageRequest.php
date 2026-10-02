<?php

namespace App\Http\Requests\Chat;

/**
 * Crear una tarea desde un mensaje de un chat de proyecto (SPEC §12). Las reglas del dominio
 * (bolsa abierta y obligatoria en proyectos de bolsas, responsable interno activo…) las aplica
 * App\Domain\Tasks\TaskWriter.
 */
class StoreTaskFromMessageRequest extends ChatRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'hour_bank_id' => ['nullable', 'integer'],
            'assignee_user_id' => ['nullable', 'integer'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
