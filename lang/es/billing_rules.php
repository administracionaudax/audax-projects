<?php

/*
| Respuestas del propietario del 09/10/2026 en Facturación (D-430 a D-434): crear el cliente desde un
| contacto de Holded y «No necesita proyecto» en las facturas.
*/

return [
    'no_project' => [
        'marked' => ':invoice no necesita proyecto: ya no sale en «Sin proyecto».',
        'cleared' => ':invoice vuelve a necesitar proyecto.',
        'not_allowed' => 'Solo se puede marcar una factura sin anular y sin proyecto.',
        'draft' => 'El borrador',
    ],
];
