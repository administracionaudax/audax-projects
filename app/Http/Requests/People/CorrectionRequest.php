<?php

namespace App\Http\Requests\People;

use App\Enums\ClockEventKind;
use App\Enums\WorkMode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Propuesta de corrección de un día del registro (D-335): de quién es, qué día, los fichajes como
 * deben quedar (cada uno con su id si ya estaba) y el motivo. Lo demás lo valida
 * ClockCorrectionService (la secuencia, el futuro y los cruces con otras jornadas).
 */
class CorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'rows' => ['present', 'array', 'max:20'],
            'rows.*.id' => ['nullable', 'integer'],
            'rows.*.kind' => ['required', 'string', Rule::in(ClockEventKind::punchValues())],
            'rows.*.time' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'rows.*.next_day' => ['sometimes', 'boolean'],
            'rows.*.work_mode' => ['nullable', 'string', Rule::enum(WorkMode::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rows.*.time.regex' => __('people.errors.invalid_time'),
            'rows.*.time.required' => __('people.errors.invalid_time'),
            'rows.*.kind.*' => __('people.errors.invalid_kind'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'reason' => 'motivo',
            'date' => 'día',
        ];
    }

    /**
     * @return list<array{id?: int|null, kind: string, time: string, next_day?: bool, work_mode?: string|null}>
     */
    public function rows(): array
    {
        /** @var list<array{id?: int|null, kind: string, time: string, next_day?: bool, work_mode?: string|null}> */
        return array_values(array_map(fn (array $row): array => [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'kind' => (string) $row['kind'],
            'time' => (string) $row['time'],
            'next_day' => (bool) ($row['next_day'] ?? false),
            'work_mode' => isset($row['work_mode']) ? (string) $row['work_mode'] : null,
        ], (array) $this->input('rows', [])));
    }
}
