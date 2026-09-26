<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Versión de jornada (SPEC §4.1): desde qué fecha y minutos de cada día, lunes primero (0 a 24 h).
 */
class WorkScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage-users');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'week' => ['required', 'array', 'list', 'size:7'],
            'week.*' => ['required', 'integer', 'between:0,1440'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'week.*.between' => __('admin.schedules.day_range'),
            'week.*.required' => __('admin.schedules.day_range'),
            'week.*.integer' => __('admin.schedules.day_range'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'valid_from' => __('admin.attributes.valid_from'),
            'week' => __('admin.attributes.week'),
        ];
    }

    /**
     * @return list<int>
     */
    public function week(): array
    {
        /** @var array<int, int|string> $week */
        $week = (array) $this->input('week', []);

        return array_map(fn (int|string $minutes): int => (int) $minutes, array_values($week));
    }
}
