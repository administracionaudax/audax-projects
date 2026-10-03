<?php

namespace App\Domain\Reports\Delivery;

use App\Enums\Role;
use App\Models\ReportSchedule;
use App\Models\User;
use App\Notifications\Reports\ReportSchedulePausedNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Pausa un envío programado (D-141). Si lo pausa el programador (no una persona), avisa al
 * propietario, si sigue activo, y a los admins activos con ReportSchedulePausedNotification.
 */
final class SchedulePauser
{
    public function pause(ReportSchedule $schedule, PauseReason $reason, ?User $by = null): void
    {
        $schedule->forceFill([
            'is_active' => false,
            'paused_reason' => $reason,
            'next_run_at' => null,
        ])->save();

        DeliveryAudit::record('schedule_paused', $schedule, $by, ['reason' => $reason->value]);

        if ($reason === PauseReason::Manual) {
            return;
        }

        $owner = $schedule->owner;
        $recipients = User::query()->active()->role(Role::Admin->value)->get();

        if ($owner->isActive() && ! $recipients->contains('id', $owner->id)) {
            $recipients->push($owner);
        }

        Notification::send($recipients, new ReportSchedulePausedNotification($schedule->id, $schedule->title, $owner->name, $reason));
    }
}
