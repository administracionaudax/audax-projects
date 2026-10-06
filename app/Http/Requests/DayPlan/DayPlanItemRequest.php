<?php

namespace App\Http\Requests\DayPlan;

use App\Models\DayPlanItem;
use App\Support\Duration;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Una línea del plan del día (POST /dia/lineas y PATCH /dia/lineas/{item}): texto, cliente, proyecto,
 * tarea y horas previstas («1:30», «1,5», «90m» o minutos). El formato se valida aquí; las reglas
 * (fechas, visibilidad, coherencia) las aplica DayPlanWriter.
 */
class DayPlanItemRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $value = $this->input('planned_minutes');

        if (is_string($value) && trim($value) !== '' && ! ctype_digit(trim($value))) {
            $minutes = Duration::parse($value);
            $this->merge(['planned_minutes' => $minutes ?? $value]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'date' => [$creating ? 'required' : 'prohibited', 'date_format:Y-m-d'],
            'text' => [$creating ? 'required' : 'sometimes', 'string', 'max:'.DayPlanItem::TEXT_MAX],
            'client_id' => ['sometimes', 'nullable', 'integer'],
            'project_id' => ['sometimes', 'nullable', 'integer'],
            'task_id' => ['sometimes', 'nullable', 'integer'],
            'planned_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1440'],
            'after_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = __('day_plan.attributes');

        /** @var array<string, string> */
        return is_array($attributes) ? $attributes : [];
    }

    /**
     * Los datos para DayPlanWriter: solo las claves enviadas.
     *
     * @return array{text?: string, client_id?: int|null, project_id?: int|null, task_id?: int|null, planned_minutes?: int|null}
     */
    public function lineData(): array
    {
        $data = [];

        if ($this->has('text')) {
            $data['text'] = $this->string('text')->toString();
        }

        foreach (['client_id', 'project_id', 'task_id', 'planned_minutes'] as $key) {
            if ($this->exists($key)) {
                $data[$key] = $this->input($key) === null ? null : $this->integer($key);
            }
        }

        /** @var array{text?: string, client_id?: int|null, project_id?: int|null, task_id?: int|null, planned_minutes?: int|null} */
        return $data;
    }
}
