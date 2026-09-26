<?php

namespace App\Notifications\HourBanks;

use App\Models\HourBank;
use App\Support\Duration;

/**
 * Una bolsa ha llegado a un umbral de consumo (75 %, 90 % o 100 % por defecto; SPEC §8.5).
 * Cada umbral avisa una sola vez por bolsa (D-035, HourBankLedger).
 */
class HourBankThresholdNotification extends HourBankNotification
{
    public int $threshold;

    public function __construct(HourBank $bank, int $threshold)
    {
        parent::__construct($bank);

        $this->threshold = $threshold;
    }

    public function kind(): string
    {
        return 'hour_bank.threshold';
    }

    public function title(object $notifiable): string
    {
        return $this->trans('hour_banks.notifications.threshold.title', [
            'bank' => $this->bankName,
            'threshold' => $this->threshold,
        ]);
    }

    public function body(object $notifiable): string
    {
        return $this->trans('hour_banks.notifications.threshold.body', [
            'project' => $this->projectLabel,
            'consumed' => Duration::format($this->consumedMinutes),
            'total' => Duration::format($this->totalMinutes),
            'remaining' => Duration::format(max($this->totalMinutes - $this->consumedMinutes, 0)),
        ]);
    }

    public function icon(): string
    {
        return 'gauge';
    }

    protected function hint(): string
    {
        return $this->trans('hour_banks.mail.threshold_hint');
    }
}
