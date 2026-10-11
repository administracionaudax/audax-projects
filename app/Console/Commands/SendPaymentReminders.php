<?php

namespace App\Console\Commands;

use App\Domain\Billing\Issuing\PaymentReminders;
use App\Support\LocalTime;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Los recordatorios de cobro por escalones de las facturas propias (PLAN-EMISION §3.3; E2, D-442),
 * una vez al día. Con los módulos `billing` o `invoicing` sin encender de verdad (también en modo de
 * prueba, D-239) no hace nada: nunca se manda nada a un cliente desde una prueba.
 */
#[Signature('app:billing-send-reminders')]
#[Description('Envía los recordatorios de cobro de las facturas propias que tocan hoy')]
class SendPaymentReminders extends Command
{
    public function handle(PaymentReminders $reminders): int
    {
        if (! PaymentReminders::active()) {
            $this->info((string) __('invoicing.reminders.inactive'));

            return self::SUCCESS;
        }

        $result = $reminders->run(LocalTime::today());
        $this->info((string) __('invoicing.reminders.done', $result));

        return self::SUCCESS;
    }
}
