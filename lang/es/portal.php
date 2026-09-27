<?php

/*
| Portal de cliente (Fase 5). Se usan con __('portal.…').
*/

return [
    'errors' => [
        'no_access' => 'Tu acceso al portal no está activo. Escríbenos si crees que es un error.',
    ],
    'person' => [
        'team' => 'Equipo',
    ],
    // P1 · Bolsas del portal: textos del PDF de consumo en modo portal (D-066).
    'banks' => [
        'pdf' => [
            'consumed' => [
                'approved' => 'Horas aprobadas',
                'submitted' => 'Horas enviadas y aprobadas',
            ],
            'note' => [
                'approved' => 'Solo incluye las horas ya aprobadas a fecha de :date. Las horas en curso aparecerán cuando se aprueben.',
                'submitted' => 'Incluye las horas enviadas y las ya aprobadas a fecha de :date. Las horas en borrador no aparecen.',
            ],
            'no_entries' => 'Todavía no hay horas que mostrar en esta bolsa.',
        ],
    ],
    'mail' => [
        'greeting' => 'Hola, :name:',
        'salutation' => 'Un saludo, el equipo de :company',
        'threshold' => [
            'near' => [
                'subject' => 'Tu bolsa «:bank» ha llegado al :threshold %',
                'intro' => 'La bolsa de horas «:bank» del proyecto :project ha llegado al :threshold % de las horas contratadas.',
            ],
            'exhausted' => [
                'subject' => 'Tu bolsa «:bank» se ha agotado',
                'intro' => 'La bolsa de horas «:bank» del proyecto :project ha llegado al 100 % de las horas contratadas. Hablemos para renovarla.',
            ],
            'figures' => 'Consumido: :within de :total (quedan :remaining).',
            'action' => 'Ver el detalle en el portal',
        ],
    ],
];
