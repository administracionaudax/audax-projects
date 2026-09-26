<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\NormalizesInput;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Ajustes globales (SPEC §7, §8 y §14; Setting::DEFAULTS). Los umbrales de alerta de las bolsas se
 * guardan ordenados de menor a mayor y sin repetir.
 */
class SettingsRequest extends FormRequest
{
    use NormalizesInput;

    /**
     * Redondeos del temporizador admitidos (minutos).
     */
    public const array ROUNDINGS = [1, 5, 10, 15, 30];

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
            'default_work_minutes' => ['required', 'array', 'list', 'size:7'],
            'default_work_minutes.*' => ['required', 'integer', 'between:0,1440'],
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
            'default_work_minutes' => __('admin.attributes.default_work_minutes'),
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
        ];
    }
}
