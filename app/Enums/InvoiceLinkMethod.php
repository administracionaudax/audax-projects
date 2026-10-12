<?php

namespace App\Enums;

/**
 * Cómo se enlazó una factura de Holded con un proyecto o una bolsa (Fase 12, D-388). La
 * sincronización rehace los automáticos (código F y proyecto de Holded) y nunca toca los manuales.
 */
enum InvoiceLinkMethod: string
{
    /** El número de la factura es el código F de la bolsa o del proyecto (D-135). */
    case FCode = 'f_code';
    /** Una línea de la factura lleva un proyecto de Holded enlazado con el de Audax. */
    case HoldedProject = 'holded_project';
    /** Rectificativa sin enlaces propios: los de la factura que rectifica. */
    case Rectified = 'rectified';
    case Manual = 'manual';
    /** Factura propia creada desde el trabajo (Por facturar, E3): el proyecto y la bolsa de sus líneas. */
    case Work = 'work';

    public function label(): string
    {
        return __("billing.enums.link_method.{$this->value}");
    }

    public function automatic(): bool
    {
        return $this !== self::Manual && $this !== self::Work;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
