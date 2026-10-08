<?php

namespace App\Enums;

/**
 * Estado de cobro de una factura leída de Holded (Fase 12, D-386), calculado en la sincronización a
 * partir de lo cobrado, lo pendiente y el vencimiento (H-047): vencida si queda algo por cobrar y
 * el vencimiento ya pasó.
 */
enum CollectionStatus: string
{
    case Paid = 'paid';
    case Partial = 'partial';
    case Unpaid = 'unpaid';
    case Overdue = 'overdue';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __("billing.enums.collection_status.{$this->value}");
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
