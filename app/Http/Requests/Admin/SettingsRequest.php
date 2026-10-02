<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\NormalizesInput;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Ajustes globales (SPEC §7, §8 y §14; Setting::DEFAULTS). Los umbrales de alerta de las bolsas se
 * guardan ordenados de menor a mayor y sin repetir. Los de ocupación del resumen semanal (D-047)
 * exigen que el bajo sea menor que el alto.
 */
class SettingsRequest extends FormRequest
{
    use NormalizesInput;

    /**
     * Redondeos del temporizador admitidos (minutos).
     */
    public const array ROUNDINGS = [1, 5, 10, 15, 30];

    /**
     * Duración máxima de los audios del chat (segundos). El techo de 10 minutos lo pone el
     * transcriptor: tarda unas 3,6 veces la duración del audio y su tiempo máximo es de 40 minutos (D-070).
     */
    public const int MIN_AUDIO_SECONDS = 30;

    public const int MAX_AUDIO_SECONDS = 600;

    /**
     * Límites de los umbrales de ocupación del resumen semanal (%, D-047).
     */
    public const int OCCUPANCY_MIN = 1;

    public const int OCCUPANCY_MAX = 300;

    public function authorize(): bool
    {
        return Gate::allows('manage-settings');
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['company_name']);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:120'],
            'require_2fa' => ['required', 'boolean'],
            'timer_rounding_minutes' => ['required', 'integer', 'in:'.implode(',', self::ROUNDINGS)],
            'timer_warning_hours' => ['required', 'integer', 'between:1,24'],
            'hour_bank_alert_thresholds' => ['required', 'array', 'list', 'min:1', 'max:5'],
            'hour_bank_alert_thresholds.*' => ['required', 'integer', 'between:1,200', 'distinct'],
            'allow_hour_bank_overage' => ['required', 'boolean'],
            'require_timesheet_approval' => ['required', 'boolean'],
            'allow_future_time_entries' => ['required', 'boolean'],
            'time_entry_description_required' => ['required', 'boolean'],
            'max_attachment_mb' => ['required', 'integer', 'between:1,200'],
            // Opcional en la petición: quien no lo envía conserva el valor guardado (Fase 6).
            'max_audio_seconds' => ['sometimes', 'required', 'integer', 'between:'.self::MIN_AUDIO_SECONDS.','.self::MAX_AUDIO_SECONDS],
            'default_work_minutes' => ['required', 'array', 'list', 'size:7'],
            'default_work_minutes.*' => ['required', 'integer', 'between:0,1440'],
            'weekly_digest_enabled' => ['required', 'boolean'],
            'occupancy_low_threshold' => ['required', 'integer', 'between:'.self::OCCUPANCY_MIN.','.self::OCCUPANCY_MAX, 'lt:occupancy_high_threshold'],
            'occupancy_high_threshold' => ['required', 'integer', 'between:'.self::OCCUPANCY_MIN.','.self::OCCUPANCY_MAX],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'timer_rounding_minutes.in' => __('admin.settings.errors.rounding'),
            'hour_bank_alert_thresholds.*.distinct' => __('admin.settings.errors.thresholds_distinct'),
            'hour_bank_alert_thresholds.*.between' => __('admin.settings.errors.thresholds_range'),
            'hour_bank_alert_thresholds.*.integer' => __('admin.settings.errors.thresholds_range'),
            'hour_bank_alert_thresholds.*.required' => __('admin.settings.errors.thresholds_range'),
            'hour_bank_alert_thresholds.min' => __('admin.settings.errors.thresholds_count'),
            'hour_bank_alert_thresholds.max' => __('admin.settings.errors.thresholds_count'),
            'default_work_minutes.*.between' => __('admin.schedules.day_range'),
            'default_work_minutes.*.integer' => __('admin.schedules.day_range'),
            'default_work_minutes.*.required' => __('admin.schedules.day_range'),
            'occupancy_low_threshold.lt' => __('reports.r3.settings.low_below_high'),
            'occupancy_low_threshold.between' => __('reports.r3.settings.range', ['min' => self::OCCUPANCY_MIN, 'max' => self::OCCUPANCY_MAX]),
            'occupancy_low_threshold.integer' => __('reports.r3.settings.range', ['min' => self::OCCUPANCY_MIN, 'max' => self::OCCUPANCY_MAX]),
            'occupancy_high_threshold.between' => __('reports.r3.settings.range', ['min' => self::OCCUPANCY_MIN, 'max' => self::OCCUPANCY_MAX]),
            'occupancy_high_threshold.integer' => __('reports.r3.settings.range', ['min' => self::OCCUPANCY_MIN, 'max' => self::OCCUPANCY_MAX]),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'company_name' => __('admin.attributes.company_name'),
            'timer_warning_hours' => __('admin.attributes.timer_warning_hours'),
            'hour_bank_alert_thresholds' => __('admin.attributes.thresholds'),
            'max_attachment_mb' => __('admin.attributes.max_attachment_mb'),
            'max_audio_seconds' => __('chat_media.attributes.max_audio_seconds'),
            'default_work_minutes' => __('admin.attributes.default_work_minutes'),
            'occupancy_low_threshold' => __('reports.r3.settings.attributes.low'),
            'occupancy_high_threshold' => __('reports.r3.settings.attributes.high'),
        ];
    }

    /**
     * Valores listos para Setting::set, con sus tipos.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        /** @var array<int, int|string> $thresholds */
        $thresholds = (array) $this->input('hour_bank_alert_thresholds', []);
        $thresholds = array_values(array_unique(array_map('intval', $thresholds)));
        sort($thresholds);

        /** @var array<int, int|string> $week */
        $week = (array) $this->input('default_work_minutes', []);

        return [
            'company_name' => $this->string('company_name')->toString(),
            'require_2fa' => $this->boolean('require_2fa'),
            'timer_rounding_minutes' => $this->integer('timer_rounding_minutes'),
            'timer_warning_hours' => $this->integer('timer_warning_hours'),
            'hour_bank_alert_thresholds' => $thresholds,
            'allow_hour_bank_overage' => $this->boolean('allow_hour_bank_overage'),
            'require_timesheet_approval' => $this->boolean('require_timesheet_approval'),
            'allow_future_time_entries' => $this->boolean('allow_future_time_entries'),
            'time_entry_description_required' => $this->boolean('time_entry_description_required'),
            'max_attachment_mb' => $this->integer('max_attachment_mb'),
            'default_work_minutes' => array_map('intval', array_values($week)),
            ...($this->has('max_audio_seconds') ? ['max_audio_seconds' => $this->integer('max_audio_seconds')] : []),
            'weekly_digest_enabled' => $this->boolean('weekly_digest_enabled'),
            'occupancy_low_threshold' => $this->integer('occupancy_low_threshold'),
            'occupancy_high_threshold' => $this->integer('occupancy_high_threshold'),
        ];
    }
}
