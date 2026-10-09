<?php

namespace App\Enums;

/**
 * Cómo rectifica una rectificativa propia (D-244, D-424): por el total (Anular) o por diferencias
 * (Rectificar). La rectificación por sustitución queda para cuando la pida la gestoría (G-2).
 */
enum RectificationKind: string
{
    case Cancellation = 'cancellation';
    case Differences = 'differences';

    public function label(): string
    {
        return __("invoicing.enums.rectification.{$this->value}");
    }
}
