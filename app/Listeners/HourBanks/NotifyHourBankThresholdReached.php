<?php

namespace App\Listeners\HourBanks;

use App\Domain\HourBanks\Events\HourBankThresholdReached;
use App\Domain\HourBanks\HourBankAlertRecipients;
use App\Enums\ProjectAlert;
use App\Notifications\HourBanks\HourBankThresholdNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Umbral de consumo alcanzado (SPEC §8.5): avisa en la app y por email a los gestores que tienen
 * activada la alerta, a los responsables del departamento de la bolsa y a los admins (D-023,
 * D-035). El mensaje de sistema en el chat del proyecto llega con el chat (Fase 6).
 */
class NotifyHourBankThresholdReached
{
    public function __construct(private readonly HourBankAlertRecipients $recipients) {}

    public function handle(HourBankThresholdReached $event): void
    {
        $bank = $event->hourBank;
        $recipients = $this->recipients->for($bank, ProjectAlert::HourBankThreshold);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new HourBankThresholdNotification(
            $bank,
            $event->threshold,
        ));
    }
}
