<?php

namespace App\Http\Requests\Weeklies;

use App\Enums\WeeklyReminderChannel;
use App\Enums\WeeklyReminderTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Envío manual de un recordatorio (F-109): a todas las personas pendientes de la semana activa o a
 * las elegidas (que también tienen que estar pendientes), con la plantilla automática o la manual y
 * por los canales elegidos. Quien gestiona la Weekly.
 */
final class SendWeeklyRemindersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage-weeklies');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'recipients' => ['required', Rule::in(['pending', 'users'])],
            'user_ids' => ['exclude_unless:recipients,users', 'required', 'array', 'min:1', 'max:200'],
            'user_ids.*' => ['integer'],
            'template' => ['required', Rule::in([WeeklyReminderTemplate::Automatic->value, WeeklyReminderTemplate::Manual->value])],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['distinct', Rule::enum(WeeklyReminderChannel::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_ids.required' => __('weeklies.reminders.validation.users'),
            'user_ids.min' => __('weeklies.reminders.validation.users'),
            'channels.required' => __('weeklies.reminders.validation.channels'),
            'channels.min' => __('weeklies.reminders.validation.channels'),
            'channels.*.*' => __('weeklies.reminders.validation.channel'),
        ];
    }

    /**
     * @return list<int>|null null = todas las pendientes
     */
    public function userIds(): ?array
    {
        if ($this->validated('recipients') !== 'users') {
            return null;
        }

        /** @var list<int|string> $ids */
        $ids = $this->validated('user_ids', []);

        return array_values(array_unique(array_map(intval(...), $ids)));
    }

    /**
     * @return list<WeeklyReminderChannel>
     */
    public function channels(): array
    {
        /** @var list<string> $channels */
        $channels = $this->validated('channels', []);

        return array_map(WeeklyReminderChannel::from(...), $channels);
    }

    public function template(): WeeklyReminderTemplate
    {
        return WeeklyReminderTemplate::from((string) $this->validated('template'));
    }
}
