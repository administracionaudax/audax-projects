<?php

namespace App\Console\Commands;

use App\Domain\Billing\Recurring\RecurringInvoiceGenerator;
use App\Support\LocalTime;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Las facturas de las recurrentes que tocan hoy (PLAN-EMISION §3.1; E3), cada mañana. Idempotente:
 * un periodo ya generado u omitido no se vuelve a generar (historial con índice único). Con los
 * módulos `billing` o `invoicing` sin encender de verdad (también en modo de prueba, D-239) no hace
 * nada; «Generar ahora», a mano, sí funciona.
 */
#[Signature('app:billing-generate-recurring')]
#[Description('Genera las facturas de las recurrentes que tocan hoy (en borrador o emitidas, según cada una)')]
class GenerateRecurringInvoices extends Command
{
    public function handle(RecurringInvoiceGenerator $generator): int
    {
        if (! RecurringInvoiceGenerator::active()) {
            $this->info((string) __('invoicing_work.recurring.run.inactive'));

            return self::SUCCESS;
        }

        $result = $generator->runDue(LocalTime::today());
        $this->info((string) __('invoicing_work.recurring.run.done', $result));

        return self::SUCCESS;
    }
}
