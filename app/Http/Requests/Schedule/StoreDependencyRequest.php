<?php

namespace App\Http\Requests\Schedule;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Nueva dependencia fin-inicio (SPEC §6.1): predecesora y sucesora del proyecto de la URL. La
 * autorización (TaskPolicy::update en las dos) y las reglas (misma tarea, ciclos) las aplican el
 * controlador y DependencyService.
 */
class StoreDependencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'predecessor_task_id' => ['required', 'integer'],
            'successor_task_id' => ['required', 'integer'],
        ];
    }
}
