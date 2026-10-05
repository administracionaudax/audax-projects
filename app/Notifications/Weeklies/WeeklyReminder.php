<?php

namespace App\Notifications\Weeklies;

use App\Enums\WeeklyReminderTemplate;
use App\Models\WeeklyCycle;

/**
 * «Recuerda enviar tu weekly» (F-037, F-101, F-102, F-104, F-109 y F-110): el de las reglas
 * (plantilla automatic) y el manual o «Recordar» (plantilla manual), a quien aún debe enviarla. Lleva
 * a «Mi weekly» de esa semana.
 */
class WeeklyReminder extends WeeklyNotice
{
    public string $template;

    public function __construct(WeeklyCycle $cycle, WeeklyReminderTemplate $template, string $subject, string $mailBody)
    {
        parent::__construct($cycle, $subject, $mailBody);

        $this->template = $template->value;
    }

    public function kind(): string
    {
        return 'weeklies.reminder';
    }

    public function body(object $notifiable): ?string
    {
        return self::text('weeklies.reminders.notice.reminder_body', ['label' => $this->cycleLabel, 'deadline' => $this->deadlineText()]);
    }

    protected function path(): string
    {
        return route('my-space.index', ['semana' => $this->cycleId], absolute: false);
    }

    protected function actionLabel(): string
    {
        return self::text('weeklies.reminders.notice.reminder_action');
    }
}
