<?php

/*
|--------------------------------------------------------------------------
| Emisión propia de facturas (PLAN-EMISION, E1; D-417 a D-430)
|--------------------------------------------------------------------------
| Datos fijos del sistema informático de facturación (SIF) que van en el registro de cada factura
| (RD 1007/2023, art. 10; PLAN-EMISION §5.3): nombre, código de dos caracteres y versión. En E1 no
| se envía nada a la AEAT (D-249): el registro encadenado con su huella se guarda para E7.
| Nunca se llama a la AEAT desde aquí.
*/

return [
    'system' => [
        'name' => 'Audax Proyectos',
        'code' => 'AP',
        // Sube con cada despliegue que toque la emisión (la declaración responsable de E7 va por versión).
        'version' => env('INVOICING_SYSTEM_VERSION', '1.0'),
    ],

    // Disco privado de los PDF emitidos (nunca en una purga, en la copia nocturna; L-16 y L-18).
    'disk' => env('INVOICING_DISK', 'local'),
];
