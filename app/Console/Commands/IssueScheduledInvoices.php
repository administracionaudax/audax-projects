<?php

namespace App\Console\Commands;

use App\Domain\Billing\Recurring\RecurringInvoiceGenerator;
use App\Domain\Billing\Recurring\ScheduledInvoices;
use App\Support\LocalTime;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Emite (y, si se eligió, envía) las facturas programadas para hoy (PLAN-EMISION §3.1; E3), cada
 * mañana a las 08:00. Solo con la emisión encendida de verdad (en modo de prueba no se emite nada
 * solo, D-239). Lo que no se puede emitir sigue programado con su motivo y se intenta otra vez al día
 * siguiente.
 */
#[Signature('app:billing-issue-scheduled')]
#[Description('Emite las facturas programadas para hoy')]
class IssueScheduledInvoices extends Command
{
    public function handle(ScheduledInvoices $scheduled): int
    {
        if (! RecurringInvoiceGenerator::active()) {
            $this->info((string) __('invoicing_work.scheduled.run.inactive'));

            return self::SUCCESS;
        }

        $result = $scheduled->runDue(LocalTime::today());
        $this->info((string) __('invoicing_work.scheduled.run.done', $result));

        return self::SUCCESS;
    }
}
