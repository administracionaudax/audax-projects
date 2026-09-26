<?php

namespace App\Listeners\HourBanks;

use App\Domain\HourBanks\Events\HourBankOverageRecorded;
use App\Domain\HourBanks\HourBankAlertRecipients;
use App\Enums\ProjectAlert;
use App\Notifications\HourBanks\HourBankOverageNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Horas en exceso sobre una bolsa agotada (SPEC §8.6): como máximo un aviso al día por bolsa
 * (lo garantiza HourBankLedger). Mismos destinatarios que los umbrales, con la alerta de exceso.
 */
class NotifyHourBankOverageRecorded
{
    public function __construct(private readonly HourBankAlertRecipients $recipients) {}

    public function handle(HourBankOverageRecorded $event): void
    {
        $bank = $event->hourBank;
        $recipients = $this->recipients->for($bank, ProjectAlert::HourBankOverage);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new HourBankOverageNotification(
            $bank,
            $event->addedOverageMinutes,
        ));
    }
}
