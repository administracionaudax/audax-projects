<?php

namespace App\Domain\Integrations\Google;

/**
 * Por qué se desconectó una cuenta de Google (D-142), para la auditoría: la persona desde Ajustes,
 * o de forma automática al darla de baja, al pasarla a colaborador externo o porque Google retiró
 * el acceso.
 */
enum GoogleDisconnectReason: string
{
    case Manual = 'manual';
    case Deactivated = 'deactivated';
    case BecameCollaborator = 'collaborator';
    case RevokedByGoogle = 'revoked';

    public function label(): string
    {
        $label = __("audit.values.google_disconnect_reasons.{$this->value}");

        return is_string($label) ? $label : $this->value;
    }
}
