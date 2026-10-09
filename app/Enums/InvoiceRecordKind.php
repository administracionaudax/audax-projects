<?php

namespace App\Enums;

/** Registro de facturación (PLAN-EMISION §4.3; D-420): alta al emitir o anulación por error (V-03). */
enum InvoiceRecordKind: string
{
    case Alta = 'alta';
    case Anulacion = 'anulacion';
}
