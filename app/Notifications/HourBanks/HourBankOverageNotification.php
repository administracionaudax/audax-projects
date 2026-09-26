<?php

namespace App\Notifications\HourBanks;

use App\Models\HourBank;
use App\Support\Duration;

/**
 * Se han registrado horas en exceso sobre una bolsa agotada (SPEC §8.6). Como máximo un aviso al
 * día por bolsa (D-035, HourBankLedger).
 */
class HourBankOverageNotification extends HourBankNotification
{
    public int $addedOverageMinutes;

    public function __construct(HourBank $bank, int $addedOverageMinutes)
    {
        parent::__construct($bank);

        $this->addedOverageMinutes = $addedOverageMinutes;
    }

    public function kind(): string
    {
        return 'hour_bank.overage';
    }

    public function title(object $notifiable): string
    {
        return $this->trans('hour_banks.notifications.overage.title', ['bank' => $this->bankName]);
    }

    public function body(object $notifiable): string
    {
        return $this->trans('hour_banks.notifications.overage.body', [
            'project' => $this->projectLabel,
            'added' => Duration::format($this->addedOverageMinutes),
            'overage' => Duration::format($this->overageMinutes),
            'total' => Duration::format($this->totalMinutes),
        ]);
    }

    public function icon(): string
    {
        return 'triangle-alert';
    }

    protected function hint(): string
    {
        return $this->trans('hour_banks.mail.overage_hint');
    }
}
