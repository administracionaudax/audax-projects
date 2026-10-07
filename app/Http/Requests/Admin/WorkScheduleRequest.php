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
            // Registro de jornada (Fase 11, D-336): margen de entrada, pausa prevista y verano.
            'start_time_from' => ['nullable', 'date_format:H:i', 'required_with:start_time_to'],
            'start_time_to' => ['nullable', 'date_format:H:i', 'required_with:start_time_from', 'after_or_equal:start_time_from'],
            'expected_pause_minutes' => ['sometimes', 'integer', 'between:0,240'],
            'summer' => ['sometimes', 'boolean'],
            'summer_starts_on' => ['exclude_unless:summer,true', 'required', 'string', 'regex:/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/'],
            'summer_ends_on' => ['exclude_unless:summer,true', 'required', 'string', 'regex:/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/'],
            'summer_week' => ['exclude_unless:summer,true', 'required', 'array', 'list', 'size:7'],
            'summer_week.*' => ['exclude_unless:summer,true', 'required', 'integer', 'between:0,1440'],
            'summer_expected_pause_minutes' => ['exclude_unless:summer,true', 'nullable', 'integer', 'between:0,240'],
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
            'summer_week.*.between' => __('admin.schedules.day_range'),
            'summer_week.*.required' => __('admin.schedules.day_range'),
            'summer_week.*.integer' => __('admin.schedules.day_range'),
            'start_time_to.after_or_equal' => __('people.schedule.window_order'),
            'start_time_from.required_with' => __('people.schedule.window_pair'),
            'start_time_to.required_with' => __('people.schedule.window_pair'),
            'summer_starts_on.required' => __('people.schedule.summer_pair'),
            'summer_ends_on.required' => __('people.schedule.summer_pair'),
            'summer_starts_on.regex' => __('people.schedule.summer_date'),
            'summer_ends_on.regex' => __('people.schedule.summer_date'),
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
     * Margen de entrada, pausa prevista y temporada de verano (Fase 11, D-336), como columnas de la
     * versión. Sin verano, sus columnas a null.
     *
     * @return array{start_time_from: string|null, start_time_to: string|null, expected_pause_minutes: int, summer_starts_on: string|null, summer_ends_on: string|null, summer_week: list<int>|null, summer_expected_pause_minutes: int|null}
     */
    public function register(): array
    {
        $summer = $this->boolean('summer');
        /** @var array<int, int|string> $summerWeek */
        $summerWeek = (array) $this->input('summer_week', []);

        return [
            'start_time_from' => $this->filled('start_time_from') ? $this->string('start_time_from')->toString() : null,
            'start_time_to' => $this->filled('start_time_to') ? $this->string('start_time_to')->toString() : null,
            'expected_pause_minutes' => $this->integer('expected_pause_minutes'),
            'summer_starts_on' => $summer ? $this->string('summer_starts_on')->toString() : null,
            'summer_ends_on' => $summer ? $this->string('summer_ends_on')->toString() : null,
            'summer_week' => $summer ? array_map(fn (int|string $minutes): int => (int) $minutes, array_values($summerWeek)) : null,
            'summer_expected_pause_minutes' => $summer ? $this->integer('summer_expected_pause_minutes') : null,
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
