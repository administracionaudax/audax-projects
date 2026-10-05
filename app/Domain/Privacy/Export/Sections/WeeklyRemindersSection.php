<?php

namespace App\Domain\Privacy\Export\Sections;

use App\Models\User;
use App\Models\WeeklyReminderLog;

/**
 * Los avisos de la weekly que te han enviado (Fase 10, 10.5, F-108): semana, tipo, canal, estado,
 * el error si falló y el nombre y el email con los que se envió. Quién lo envió a mano no se
 * incluye: es otra persona. Se borran con su plazo de retención (D-202).
 */
final class WeeklyRemindersSection extends Section
{
    public const int CHUNK = 500;

    public function key(): string
    {
        return 'weeklies-avisos';
    }

    protected function textKey(): string
    {
        return 'weekly_reminders';
    }

    protected function columnKeys(): array
    {
        return ['id', 'week', 'template', 'channel', 'status', 'error', 'recipient_name', 'recipient_email', 'created_at'];
    }

    public function rows(User $user): iterable
    {
        $logs = WeeklyReminderLog::query()
            ->where('user_id', $user->id)
            ->with('cycle:id,number')
            ->lazyById(self::CHUNK);

        foreach ($logs as $log) {
            yield [
                'id' => $log->id,
                'week' => $log->cycle?->number,
                'template' => $log->template->label(),
                'channel' => $log->channel->label(),
                'status' => $log->status->label(),
                'error' => $log->error,
                'recipient_name' => $log->recipient_name,
                'recipient_email' => $log->recipient_email,
                'created_at' => self::instant($log->created_at),
            ];
        }
    }
}
