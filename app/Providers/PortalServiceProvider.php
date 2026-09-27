<?php

namespace App\Providers;

use App\Domain\Portal\PortalBankAlerts;
use App\Models\TimeEntry;
use Illuminate\Support\ServiceProvider;

/**
 * Portal de cliente (Fase 5): los avisos de bolsa al cliente (D-065) se comprueban cuando cambia
 * una entrada de la bolsa (alta, edición, aprobación, borrado), una vez por bolsa y transacción.
 */
class PortalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PortalBankAlerts::class);
    }

    public function boot(): void
    {
        $queue = function (TimeEntry $entry): void {
            $alerts = $this->app->make(PortalBankAlerts::class);
            $alerts->queue($entry->hour_bank_id);

            $previous = $entry->getOriginal('hour_bank_id');
            if (is_int($previous) && $previous !== $entry->hour_bank_id) {
                $alerts->queue($previous);
            }
        };

        TimeEntry::saved($queue);
        TimeEntry::deleted($queue);
    }
}
