<?php

namespace App\Http\Requests\Weeklies;

use App\Domain\Weeklies\WeeklyDraftData;
use App\Enums\WeeklyEntrySource;
use App\Models\WeeklyCycle;
use App\Models\WeeklySubmission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Mi weekly de una semana (F-044 a F-052): el borrador autoguardado y el envío. Solo la propia
 * persona y con la semana activa (WeeklySubmissionPolicy::create); el resto de reglas (que le toque
 * y no esté exenta) las aplica WeeklySubmissionWriter. Se puede enviar sin apuntes, como en
 * WeeklySync (10.9b, D-230: cambia la regla 7 del contrato); la interfaz pide confirmarlo.
 */
final class SaveWeeklyDraftRequest extends FormRequest
{
    public const int MAX_ENTRIES = 200;

    public const int MAX_BODY = 20000;

    public function authorize(): bool
    {
        $cycle = $this->route('cycle');

        return $cycle instanceof WeeklyCycle && Gate::allows('create', [WeeklySubmission::class, $cycle]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'entries' => ['present', 'array', 'max:'.self::MAX_ENTRIES],
            'entries.*' => ['array:client_id,project_id,body,source'],
            'entries.*.client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'entries.*.project_id' => ['nullable', 'integer'],
            'entries.*.body' => ['nullable', 'string', 'max:'.self::MAX_BODY],
            'entries.*.source' => ['nullable', Rule::enum(WeeklyEntrySource::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'entries.present' => __('weeklies.validation.entries'),
            'entries.array' => __('weeklies.validation.entries'),
            'entries.max' => __('weeklies.validation.entries'),
            'entries.*.client_id.exists' => __('weeklies.validation.client'),
            'entries.*.body.max' => __('weeklies.validation.body_too_long', ['max' => self::MAX_BODY]),
        ];
    }

    public function draft(): WeeklyDraftData
    {
        $entries = $this->input('entries', []);

        return WeeklyDraftData::fromArray(is_array($entries) ? $entries : []);
    }
}
