<?php

namespace App\Http\Requests\Weeklies;

use App\Domain\Weeklies\Reminders\WeeklyTemplates;
use App\Enums\WeeklyReminderChannel;
use App\Enums\WeeklyReminderTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * «Avisos de la Weekly» (F-101 a F-105, D-199): las reglas (la lista entera: las que no llegan se
 * borran), las plantillas editables y la weekly en el recordatorio de los viernes. Quien gestiona la
 * Weekly (`manage-weeklies`).
 */
final class UpdateWeeklyRemindersRequest extends FormRequest
{
    public const int MAX_RULES = 20;

    public function authorize(): bool
    {
        return Gate::allows('manage-weeklies');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'rules' => ['present', 'array', 'max:'.self::MAX_RULES],
            'rules.*.id' => ['nullable', 'integer'],
            'rules.*.channel' => ['required', Rule::enum(WeeklyReminderChannel::class)],
            'rules.*.day_of_week' => ['required', 'integer', 'between:1,7'],
            'rules.*.time' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'rules.*.enabled' => ['required', 'boolean'],
            'templates' => ['sometimes', 'array'],
            'friday_reminder' => ['sometimes', 'boolean'],
        ];

        foreach (WeeklyReminderTemplate::EDITABLE as $key) {
            $rules["templates.{$key}"] = ['sometimes', 'array'];
            $rules["templates.{$key}.subject"] = ["required_with:templates.{$key}", 'string', 'max:'.WeeklyTemplates::SUBJECT_MAX];
            $rules["templates.{$key}.body"] = ["required_with:templates.{$key}", 'string', 'max:'.WeeklyTemplates::BODY_MAX];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rules.max' => __('weeklies.reminders.validation.rules_max', ['max' => self::MAX_RULES]),
            'rules.*.channel.*' => __('weeklies.reminders.validation.channel'),
            'rules.*.day_of_week.*' => __('weeklies.reminders.validation.day'),
            'rules.*.time.*' => __('weeklies.reminders.validation.time'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = ['rules' => __('weeklies.reminders.attributes.rules')];

        foreach (WeeklyReminderTemplate::EDITABLE as $key) {
            $attributes["templates.{$key}.subject"] = __('weeklies.reminders.attributes.subject');
            $attributes["templates.{$key}.body"] = __('weeklies.reminders.attributes.body');
        }

        return $attributes;
    }

    /**
     * @return list<array{id: int|null, channel: WeeklyReminderChannel, day_of_week: int, time: string, enabled: bool}>
     */
    public function reminderRules(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->validated('rules', []);

        return array_map(fn (array $row): array => [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'channel' => WeeklyReminderChannel::from((string) $row['channel']),
            'day_of_week' => (int) $row['day_of_week'],
            'time' => (string) $row['time'],
            'enabled' => (bool) $row['enabled'],
        ], $rows);
    }

    /**
     * @return array<string, array{subject: string, body: string}>
     */
    public function templates(): array
    {
        /** @var array<string, array{subject: string, body: string}> $templates */
        $templates = $this->validated('templates', []);

        return array_intersect_key($templates, array_flip(WeeklyReminderTemplate::EDITABLE));
    }
}
