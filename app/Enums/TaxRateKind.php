<?php

namespace App\Enums;

/** Clase de impuesto del catálogo (D-423): IVA por línea o retención de IRPF por factura. */
enum TaxRateKind: string
{
    case Vat = 'vat';
    case Withholding = 'withholding';
}
