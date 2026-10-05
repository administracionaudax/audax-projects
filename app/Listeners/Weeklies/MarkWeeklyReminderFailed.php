<?php

namespace App\Listeners\Weeklies;

use Illuminate\Notifications\Events\NotificationFailed;
use Throwable;

/**
 * Un aviso de la weekly que no se ha podido entregar por un canal (p. ej. el SMTP rechaza el
 * email) queda «fallido» con el error en el registro (F-108, D-201). Si la cola lo reintenta y
 * llega, afterSending() lo vuelve a dejar «enviado».
 */
class MarkWeeklyReminderFailed
{
    public function handle(NotificationFailed $event): void
    {
        if (! method_exists($event->notification, 'markReminderFailed')) {
            return;
        }

        $exception = $event->data['exception'] ?? null;
        $error = $exception instanceof Throwable ? $exception->getMessage() : __('weeklies.reminders.failed');

        $event->notification->markReminderFailed($event->channel, is_string($error) && $error !== '' ? $error : 'error');
    }
}
