<?php

namespace App\Http\Requests\Weeklies;

use App\Models\WeeklyCycle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Plazo de la semana activa (F-068): quien gestiona la Weekly (WeeklyCyclePolicy::extendDeadline).
 * Un día entre el lunes de la semana y cuatro semanas después del viernes.
 */
final class UpdateWeeklyDeadlineRequest extends FormRequest
{
    public const int MAX_DAYS_AFTER_END = 28;

    public function authorize(): bool
    {
        $cycle = $this->route('cycle');

        return $cycle instanceof WeeklyCycle && Gate::allows('extendDeadline', $cycle);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var WeeklyCycle $cycle */
        $cycle = $this->route('cycle');
        $start = $cycle->start_date->toDateString();
        $limit = CarbonImmutable::parse($cycle->end_date->toDateString())->addDays(self::MAX_DAYS_AFTER_END)->toDateString();

        return [
            'deadline_date' => ['required', 'date_format:Y-m-d', "after_or_equal:{$start}", "before_or_equal:{$limit}"],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'deadline_date.required' => __('weeklies.validation.deadline_required'),
            'deadline_date.date_format' => __('weeklies.validation.deadline_required'),
            'deadline_date.after_or_equal' => __('weeklies.validation.deadline_range'),
            'deadline_date.before_or_equal' => __('weeklies.validation.deadline_range'),
        ];
    }
}
