<?php

namespace App\Domain\Weeklies\Reminders;

use App\Enums\WeeklyReminderStatus;
use App\Models\WeeklyReminderLog;

/**
 * Para las notificaciones que cierran filas de weekly_reminder_logs (D-201): canal de Laravel →
 * filas que ese canal entrega. Al entregarse, Laravel llama a afterSending() y la fila pasa a
 * «enviada»; si el canal falla, MarkWeeklyReminderFailed la deja «fallida» con el error.
 */
trait TracksWeeklyReminderLogs
{
    /** @var array<string, list<int>> */
    public array $reminderLogIds = [];

    public function afterSending(object $notifiable, string $channel, mixed $response = null): void
    {
        $ids = $this->reminderLogIds[$channel] ?? [];

        if ($ids !== []) {
            WeeklyReminderLog::query()->whereKey($ids)->update([
                'status' => WeeklyReminderStatus::Sent->value,
                'error' => null,
                'updated_at' => now(),
            ]);
        }
    }

    public function markReminderFailed(string $channel, string $error): void
    {
        $ids = $this->reminderLogIds[$channel] ?? [];

        if ($ids !== []) {
            WeeklyReminderLog::query()->whereKey($ids)->update([
                'status' => WeeklyReminderStatus::Failed->value,
                'error' => mb_substr($error, 0, 1000),
                'updated_at' => now(),
            ]);
        }
    }
}
