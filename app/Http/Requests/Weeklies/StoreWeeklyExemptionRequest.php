<?php

namespace App\Http\Requests\Weeklies;

use App\Models\WeeklyCycle;
use App\Models\WeeklyExemption;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Eximir a una persona de la weekly de la semana activa (F-038): quien gestiona la Weekly. Que
 * participe esa semana lo comprueba el controlador con WeeklyEligibility.
 */
final class StoreWeeklyExemptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $cycle = $this->route('cycle');

        return $cycle instanceof WeeklyCycle && Gate::allows('create', [WeeklyExemption::class, $cycle]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.required' => __('weeklies.validation.person'),
            'user_id.integer' => __('weeklies.validation.person'),
            'note.max' => __('weeklies.validation.note_too_long'),
        ];
    }
}
