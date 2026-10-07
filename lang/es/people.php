<?php

/*
|--------------------------------------------------------------------------
| Personas: registro de jornada (Fase 11, R1; docs/PLAN-FASE-11.md)
|--------------------------------------------------------------------------
| Textos del servidor: tipos de fichaje, modos, estados de las correcciones, incidencias, errores
| de ClockWriter y ClockCorrectionService, avisos y la ficha laboral. Los de las pantallas están en
| lang/ui/people.json.
*/

return [
    'kinds' => [
        'clock_in' => 'Entrada',
        'pause_start' => 'Inicio de la pausa',
        'pause_end' => 'Vuelta de la pausa',
        'clock_out' => 'Salida',
        'void' => 'Anulación',
    ],

    'modes' => [
        'on_site' => 'Presencial',
        'remote' => 'A distancia',
    ],

    'pauses' => [
        'meal' => 'Comida',
    ],

    'sources' => [
        'web' => 'Web',
        'pwa' => 'App',
        'correction' => 'Corrección',
    ],

    'correction_status' => [
        'pending' => 'Pendiente',
        'accepted' => 'Aceptada',
        'disputed' => 'En discrepancia',
        'withdrawn' => 'Retirada',
    ],

    'incidents' => [
        'missing_clock_out' => 'Falta la salida',
        'no_records' => 'Sin fichajes',
        'during_absence' => 'Fichajes durante una ausencia',
        'short_day' => 'Menos horas',
        'short_rest' => 'Menos de 12 h de descanso',
        'long_stretch' => 'Más de 6 h seguidas sin pausa',
        'over_nine_hours' => 'Más de 9 h en el día',
        'pause_open' => 'Salida en la pausa',
    ],

    'errors' => [
        'not_subject' => 'No estás sujeto al registro de jornada: no tienes que fichar.',
        'clock_behind' => 'La hora del servidor es anterior a tu último fichaje. Avisa a administración.',
        'transition' => [
            'clock_in' => [
                'working' => 'Ya has fichado la entrada: estás trabajando.',
                'paused' => 'Estás en la pausa: ficha la vuelta o la salida.',
            ],
            'pause_start' => [
                'off' => 'Primero ficha la entrada.',
                'closed' => 'Ya has fichado la salida.',
                'paused' => 'Ya estás en la pausa.',
            ],
            'pause_end' => [
                'off' => 'No estás en la pausa.',
                'closed' => 'Ya has fichado la salida.',
                'working' => 'No estás en la pausa.',
            ],
            'clock_out' => [
                'off' => 'No tienes ninguna jornada abierta.',
                'closed' => 'Ya has fichado la salida.',
            ],
        ],
        'future_day' => 'No se puede corregir un día que aún no ha llegado.',
        'pending_exists' => 'Ya hay una corrección pendiente de ese día. Espera a que se decida o retírala.',
        'no_changes' => 'No has cambiado nada.',
        'invalid_kind' => 'Elige un tipo de fichaje.',
        'invalid_time' => 'Escribe la hora como 09:00.',
        'future_time' => 'No puede haber fichajes en el futuro.',
        'clock_in_other_day' => 'Las entradas tienen que ser de ese día.',
        'invalid_sequence' => 'El orden no cuadra: cada jornada empieza con una entrada, la pausa se cierra con la vuelta y todo acaba con una salida.',
        'day_left_open' => 'El día ya ha pasado: la jornada tiene que acabar con una salida.',
        'overlaps' => 'Se cruza con otra jornada.',
        'day_changed' => 'El día ha cambiado desde que se propuso la corrección. Hay que retirarla y proponer otra.',
        'already_decided' => 'Esta corrección ya está decidida.',
        'note_required' => 'Explica por qué no estás de acuerdo.',
    ],

    'flash' => [
        'clock_in' => 'Entrada fichada a las :time.',
        'pause_start' => 'Pausa fichada a las :time.',
        'pause_end' => 'Vuelta fichada a las :time.',
        'clock_out' => 'Salida fichada a las :time.',
        'proposed' => 'Corrección propuesta. Te avisaremos cuando la otra parte la decida.',
        'accepted' => 'Corrección aceptada: ya cuenta en el registro.',
        'accepted_many' => '{1} Corrección aceptada.|[2,*] :count correcciones aceptadas.',
        'rejected' => 'Corrección rechazada: queda en discrepancia.',
        'withdrawn' => 'Corrección retirada.',
        'employment_saved' => 'Datos laborales guardados.',
    ],

    'notifications' => [
        'clock_in_title' => 'Aún no has fichado la entrada de hoy',
        'clock_in_body' => 'Si ya estás trabajando, ficha ahora y, si hace falta, propón la hora real con una corrección.',
        'clock_out_title' => '¿Sigues trabajando? No has fichado la salida',
        'clock_out_body' => 'Ya ha pasado tu hora de salida prevista. Si has terminado, ficha la salida.',
        'unclosed_title' => 'El :date se quedó sin cerrar en el registro',
        'unclosed_body' => 'Falta la salida o no hay ningún fichaje. Propón la corrección desde Mi jornada.',
        'requested_title' => ':name propone corregir su registro del :date',
        'requested_for_you_title' => ':name propone corregir tu registro del :date',
        'accepted_title' => ':name ha aceptado la corrección del :date',
        'rejected_title' => ':name no está de acuerdo con la corrección del :date',
        'expired_title' => 'La corrección del :date queda en discrepancia: nadie la ha decidido en 7 días',
        'disputed_body' => 'Cuenta la versión original y constan las dos.',
        'reason' => 'Motivo: :reason',
    ],

    'employment' => [
        'attributes' => [
            'hire_date' => 'fecha de alta',
            'termination_date' => 'fecha de baja',
            'subject_to_register' => 'sujeto al registro',
            'register_exemption_reason' => 'motivo',
        ],
        'termination_before_hire' => 'La baja no puede ser anterior al alta.',
        'reason_required' => 'Explica por qué no está sujeto al registro (por ejemplo, socio que no es asalariado).',
    ],

    'schedule' => [
        'window_order' => 'El final del margen de entrada no puede ser anterior al inicio.',
        'window_pair' => 'Indica el inicio y el final del margen de entrada, o ninguno.',
        'summer_pair' => 'Indica el inicio y el final de la temporada de verano.',
        'summer_date' => 'Escribe la fecha como 07-01 (mes y día).',
    ],
];
