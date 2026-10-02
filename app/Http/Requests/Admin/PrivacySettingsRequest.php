<?php

namespace App\Http\Requests\Admin;

use App\Domain\Privacy\PrivacyNotice;
use App\Domain\Privacy\PrivacySettings;
use App\Domain\Privacy\RetentionPolicy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * /admin/privacidad (D-075 y D-076): el texto informativo en markdown (se guarda tal cual; se
 * pinta saneado en el navegador), los plazos de retención con sus mínimos y máximos («sin límite»
 * solo donde RetentionPolicy lo admite), los días para descargar los datos personales y los
 * umbrales de aviso de disco y adjuntos (vacío = sin aviso).
 */
class PrivacySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        $nullable = ['attachments_warning_gb'];

        foreach (RetentionPolicy::UNLIMITED_ALLOWED as $type) {
            $nullable[] = RetentionPolicy::SETTINGS[$type];
        }

        $merge = [];

        foreach ($nullable as $key) {
            if ($this->input($key) === '') {
                $merge[$key] = null;
            }
        }

        if (is_string($this->input('notice'))) {
            // Saltos de línea de Windows → Unix: el mismo texto no crea otra versión.
            $merge['notice'] = str_replace(["\r\n", "\r"], "\n", $this->input('notice'));
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'notice' => ['required', 'string', 'max:'.PrivacyNotice::MAX_LENGTH],
            'personal_data_export_days' => ['required', 'integer', 'between:'.PrivacySettings::EXPORT_DAYS_MIN.','.PrivacySettings::EXPORT_DAYS_MAX],
            'disk_warning_percent' => ['required', 'integer', 'between:'.PrivacySettings::DISK_PERCENT_MIN.','.PrivacySettings::DISK_PERCENT_MAX],
            'attachments_warning_gb' => ['nullable', 'integer', 'between:'.PrivacySettings::ATTACHMENTS_GB_MIN.','.PrivacySettings::ATTACHMENTS_GB_MAX],
        ];

        foreach (RetentionPolicy::SETTINGS as $type => $key) {
            $rules[$key] = [
                in_array($type, RetentionPolicy::UNLIMITED_ALLOWED, true) ? 'nullable' : 'required',
                'integer',
                'between:'.RetentionPolicy::MINIMUM_MONTHS[$type].','.RetentionPolicy::MAXIMUM_MONTHS,
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            'notice.required' => __('privacy.admin.errors.notice_required'),
            'notice.max' => __('privacy.admin.errors.notice_max', ['max' => PrivacyNotice::MAX_LENGTH]),
        ];

        foreach (RetentionPolicy::SETTINGS as $type => $key) {
            $range = __('privacy.admin.errors.months_range', ['min' => RetentionPolicy::MINIMUM_MONTHS[$type], 'max' => RetentionPolicy::MAXIMUM_MONTHS]);
            $messages["{$key}.between"] = $range;
            $messages["{$key}.integer"] = $range;
            $messages["{$key}.required"] = $range;
        }

        return $messages;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [
            'notice' => __('privacy.admin.attributes.notice'),
            'personal_data_export_days' => __('privacy.admin.attributes.personal_data_export_days'),
            'disk_warning_percent' => __('privacy.admin.attributes.disk_warning_percent'),
            'attachments_warning_gb' => __('privacy.admin.attributes.attachments_warning_gb'),
        ];

        foreach (RetentionPolicy::SETTINGS as $type => $key) {
            $attributes[$key] = __("privacy.retention.types.{$type}");
        }

        return $attributes;
    }

    public function notice(): string
    {
        return $this->string('notice')->toString();
    }

    /**
     * Valores para PrivacySettings::update, con sus tipos.
     *
     * @return array<string, int|null>
     */
    public function settings(): array
    {
        $values = [];

        foreach (RetentionPolicy::SETTINGS as $key) {
            $values[$key] = $this->filled($key) ? $this->integer($key) : null;
        }

        return [
            ...$values,
            'personal_data_export_days' => $this->integer('personal_data_export_days'),
            'disk_warning_percent' => $this->integer('disk_warning_percent'),
            'attachments_warning_gb' => $this->filled('attachments_warning_gb') ? $this->integer('attachments_warning_gb') : null,
        ];
    }
}
