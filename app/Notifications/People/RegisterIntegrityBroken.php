<?php

namespace App\Notifications\People;

use App\Notifications\AppNotification;

/**
 * La comprobación nocturna del registro ha encontrado algo que no cuadra (PLAN-FASE-11 §10 y
 * §11.1; D-352 y D-356): a los admins, obligatorio, en la app y por email.
 */
class RegisterIntegrityBroken extends AppNotification
{
    /**
     * @param  list<string>  $problems
     */
    public function __construct(public readonly array $problems) {}

    public function kind(): string
    {
        return 'people.integrity_broken';
    }

    public function title(object $notifiable): string
    {
        return (string) __('people.notifications.integrity_title');
    }

    public function body(object $notifiable): ?string
    {
        $shown = array_slice($this->problems, 0, 5);
        $more = count($this->problems) - count($shown);

        return implode("\n", $shown).($more > 0 ? "\n".__('people.notifications.integrity_more', ['count' => $more]) : '');
    }

    public function url(object $notifiable): ?string
    {
        return '/personas/inspeccion';
    }

    public function icon(): ?string
    {
        return 'shield-alert';
    }
}
