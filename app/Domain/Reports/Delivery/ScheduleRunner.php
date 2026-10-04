<?php

namespace App\Domain\Reports\Delivery;

use App\Jobs\SendReportDelivery;
use App\Models\ReportDelivery;
use App\Models\ReportSchedule;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;

/**
 * Pone en cola el envío de una programación (D-141), desde reports:send-scheduled o «Enviar ahora»:
 * 1. Con $advance (el programador), reclama la ejecución de forma atómica (solo si next_run_at
 *    sigue siendo el mismo: dos programadores no la envían dos veces) y calcula la siguiente; «una
 *    vez» se desactiva.
 * 2. Comprueba con los permisos ACTUALES del propietario: si está desactivado o ya no ve el
 *    informe, la pausa y avisa (SchedulePauser).
 * 3. Quita a las personas destinatarias que ya no están activas; sin destinatarios, la pausa.
 * 4. Resuelve el periodo relativo el día del envío (Madrid) y encola SendReportDelivery.
 */
final class ScheduleRunner
{
    public function __construct(
        private readonly ScheduleClock $clock,
        private readonly ReportAccess $access,
        private readonly SchedulePauser $pauser,
    ) {}

    public function run(ReportSchedule $schedule, CarbonImmutable $now, bool $advance = true, ?User $by = null): ?ReportDelivery
    {
        if ($advance && ! $this->claim($schedule, $now)) {
            return null;
        }

        $owner = $schedule->owner;

        if (! $owner->isActive()) {
            $this->pauser->pause($schedule, PauseReason::OwnerInactive);

            return null;
        }

        if (! $this->access->allows($schedule->reportRequest(), $owner)) {
            $this->pauser->pause($schedule, PauseReason::NoAccess);

            return null;
        }

        $userIds = DeliveryRecipients::users($schedule->recipient_user_ids)->modelKeys();

        if ($userIds !== $schedule->recipient_user_ids) {
            $schedule->forceFill(['recipient_user_ids' => $userIds])->save();
        }

        if ($userIds === [] && $schedule->recipient_emails === []) {
            $this->pauser->pause($schedule, PauseReason::NoRecipients);

            return null;
        }

        $today = $now->setTimezone(LocalTime::timezone())->startOfDay();
        $request = $schedule->reportRequest()->resolvedFor($schedule->relative_period, $today);

        $delivery = ReportDelivery::query()->create([
            'schedule_id' => $schedule->id,
            'sender_user_id' => $owner->id,
            'title' => $schedule->title,
            'request' => $request->toArray(),
            'formats' => $schedule->formats,
            'recipient_user_ids' => $userIds,
            'recipient_emails' => $schedule->recipient_emails,
            'subject' => $schedule->subject,
            'message' => $schedule->message,
            'status' => DeliveryStatus::Queued,
        ]);

        if (! $advance) {
            $schedule->forceFill(['last_run_at' => $now])->save();
        }

        SendReportDelivery::dispatch($delivery->id);

        return $delivery;
    }

    /**
     * Reclama esta ejecución y deja preparada la siguiente.
     */
    private function claim(ReportSchedule $schedule, CarbonImmutable $now): bool
    {
        $once = $schedule->frequency === ScheduleFrequency::Once;
        $next = $once ? null : $this->clock->nextFor($schedule, $now);

        $claimed = ReportSchedule::query()
            ->whereKey($schedule->id)
            ->where('is_active', true)
            ->where('next_run_at', $schedule->next_run_at)
            ->update([
                'is_active' => ! $once,
                'next_run_at' => $next,
                'last_run_at' => $now,
                'updated_at' => $now,
            ]);

        if ($claimed === 0) {
            return false;
        }

        $schedule->refresh();

        return true;
    }
}
