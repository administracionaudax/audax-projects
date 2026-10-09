<?php

namespace App\Notifications\Billing;

use App\Notifications\AppNotification;

/**
 * La comprobación nocturna del registro de facturación ha encontrado algo que no cuadra
 * (PLAN-EMISION §4.3; D-429): a los admins que usan la emisión, obligatorio, en la app y por email.
 */
class BillingChainBroken extends AppNotification
{
    /**
     * @param  list<string>  $problems
     */
    public function __construct(public readonly array $problems) {}

    public function kind(): string
    {
        return 'billing.chain_broken';
    }

    public function title(object $notifiable): string
    {
        return (string) __('invoicing.notifications.chain_broken_title');
    }

    public function body(object $notifiable): ?string
    {
        $shown = array_slice($this->problems, 0, 5);
        $more = count($this->problems) - count($shown);

        return implode("\n", $shown).($more > 0 ? "\n".__('invoicing.notifications.chain_more', ['count' => $more]) : '');
    }

    public function url(object $notifiable): ?string
    {
        return '/facturacion/ajustes?apartado=series';
    }

    public function icon(): ?string
    {
        return 'triangle-alert';
    }
}
