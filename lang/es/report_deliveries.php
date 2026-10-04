<?php

/*
| Envío de informes por correo y envíos programados (Fase 9, D-141): App\Domain\Reports\Delivery,
| App\Mail\ReportDeliveryMail y App\Http\Controllers\Reports\Delivery.
*/

return [
    'mail' => [
        'subject' => 'Informe: :title',
        'greeting' => 'Hola, :name:',
        'greeting_anonymous' => 'Hola:',
        'intro' => ':sender te envía este informe de Audax Proyectos.',
        'period' => 'Periodo: :period',
        'range' => 'del :from al :to',
        'attached' => '{1} Lo tienes adjunto.|[2,*] Los tienes adjuntos.',
        'too_large' => 'Es demasiado grande para ir adjunto: descárgalo desde este enlace.',
        'download' => 'Descargar :file',
        'link_expires' => 'El enlace caduca el :date.',
    ],

    'paused' => [
        'manual' => 'Lo ha pausado una persona.',
        'owner_inactive' => 'Quien lo programó ya no tiene la cuenta activa.',
        'no_access' => 'Quien lo programó ya no puede ver este informe.',
        'no_recipients' => 'No le queda ningún destinatario.',
    ],

    'notifications' => [
        'paused' => [
            'title' => 'Envío programado en pausa: «:title»',
            'body' => 'De :owner. :reason',
        ],
    ],

    'new_reports' => [
        'person' => 'Informe de :name',
        'detail' => 'Informe detallado',
        'direction' => 'Informe de dirección',
    ],

    'flash' => [
        'sent' => '{1} Envío en cola: llegará en unos minutos a 1 destinatario.|[2,*] Envío en cola: llegará en unos minutos a :count destinatarios.',
        'scheduled' => 'Envío programado. Lo tienes en Informes → Envíos programados.',
        'updated' => 'Envío programado guardado.',
        'deleted' => 'Envío programado borrado.',
        'paused' => 'Envío programado en pausa.',
        'resumed' => 'Envío programado reanudado.',
        'cannot_resume' => 'No se puede reanudar: :reason',
        'cannot_send' => 'No se puede enviar y ha quedado en pausa: :reason',
    ],

    'errors' => [
        'request' => 'No se reconoce el informe que quieres enviar.',
        'formats' => 'Elige PDF, Excel o los dos.',
        'no_recipients' => 'Añade al menos un destinatario.',
        'too_many_recipients' => 'Como mucho :max destinatarios en total.',
        'recipient_inactive' => 'Alguna de las personas elegidas ya no está activa.',
        'email' => 'Revisa este correo: no tiene un formato válido.',
        'run_date' => 'Elige la fecha del envío.',
        'run_at_past' => 'La fecha y la hora del envío ya han pasado.',
        'weekday' => 'Elige el día de la semana.',
        'month_day' => 'Elige un día del 1 al 28 o el último del mes.',
        'time' => 'Escribe la hora como HH:MM, por ejemplo 08:00.',
    ],

    'attributes' => [
        'title' => 'título',
        'subject' => 'asunto',
        'message' => 'mensaje',
        'email' => 'correo',
    ],
];
