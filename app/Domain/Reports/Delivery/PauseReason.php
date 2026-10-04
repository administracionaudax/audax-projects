<?php

namespace App\Domain\Reports\Delivery;

/**
 * Por qué está en pausa un envío programado (D-141). Manual: lo ha pausado una persona. El resto
 * los pone el programador, que avisa al propietario y a los admins (ReportSchedulePaused).
 */
enum PauseReason: string
{
    case Manual = 'manual';
    case OwnerInactive = 'owner_inactive';
    case NoAccess = 'no_access';
    case NoRecipients = 'no_recipients';

    public function label(): string
    {
        $text = __("report_deliveries.paused.{$this->value}");

        return is_string($text) ? $text : $this->value;
    }
}
