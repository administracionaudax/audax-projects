<?php

namespace App\Notifications\Weeklies;

use App\Models\WeeklyCycle;

/**
 * «Nuevo plazo para la weekly» (10.5, D-199): quien gestiona amplía o cambia el plazo de la semana
 * activa y avisa a quien aún debe enviarla. Texto fijo (no es una plantilla de WeeklySync); lleva a
 * «Mi weekly».
 */
class WeeklyDeadlineChanged extends WeeklyNotice
{
    public function __construct(WeeklyCycle $cycle)
    {
        parent::__construct($cycle);

        $this->mailBody = self::text('weeklies.reminders.notice.deadline_mail', ['deadline' => $this->deadlineText()]);
    }

    public function kind(): string
    {
        return 'weeklies.deadline_changed';
    }

    public function title(object $notifiable): string
    {
        return self::text('weeklies.reminders.notice.deadline_title');
    }

    public function body(object $notifiable): ?string
    {
        return self::text('weeklies.reminders.notice.deadline_body', ['label' => $this->cycleLabel, 'deadline' => $this->deadlineText()]);
    }

    public function icon(): ?string
    {
        return 'calendar-clock';
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
