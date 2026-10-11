<?php

/*
| Emisión propia, entrega E3 (PLAN-EMISION §3.1 y §3.8; D-450 en adelante): recurrentes, programadas,
| facturar desde el trabajo y la importación de las recurrentes de Holded. Textos de PHP (errores,
| líneas de las facturas, órdenes y avisos); los de la interfaz, en lang/ui/invoicing-work.json.
*/

return [
    'periodicity' => [
        'monthly' => 'Mensual',
        'bimonthly' => 'Bimestral',
        'quarterly' => 'Trimestral',
        'semiannual' => 'Semestral',
        'annual' => 'Anual',
        'custom' => 'Otra',
    ],

    'months' => [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
        7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ],

    'recurring' => [
        'errors' => [
            'finished' => 'Esta recurrente ya ha acabado: no le quedan facturas por generar.',
            'busy' => 'Otra persona acaba de generar este periodo. Vuelve a cargar la página.',
            'not_upcoming' => 'Ese periodo no es uno de los próximos de esta recurrente.',
            'has_documents' => 'Esta recurrente ya ha generado facturas: no se borra (su historial es la cadena de documentos). Páusala si no quieres más.',
        ],
        'flash' => [
            'saved' => 'Recurrente guardada.',
            'deleted' => 'Recurrente borrada.',
            'paused' => 'Recurrente en pausa: no generará nada hasta que la reanudes.',
            'resumed' => 'Recurrente reanudada. La próxima, el :date.',
            'skipped' => 'El periodo del :date no se generará.',
            'unskipped' => 'El periodo del :date vuelve a generarse.',
            'reviewed' => 'Marcada como revisada.',
            'generated' => 'Factura generada en borrador.',
            'generated_issued' => 'Factura generada y emitida.',
            'generated_problem' => 'Factura generada, pero se queda en borrador: :reason',
        ],
        'attributes' => [
            'name' => 'nombre',
            'client_id' => 'cliente',
            'periodicity' => 'periodicidad',
            'interval_months' => 'cada cuántos meses',
            'day_of_month' => 'día del mes',
            'start_date' => 'inicio',
            'end_date' => 'fin',
            'max_occurrences' => 'número de veces',
            'mode' => 'qué hacer',
            'lines' => 'líneas',
        ],
        'run' => [
            'inactive' => 'La emisión no está encendida de verdad (o está en modo de prueba): las recurrentes no generan nada solas.',
            'done' => 'Recurrentes: :generated generadas, :skipped omitidas y :failed con error.',
        ],
    ],

    'auto' => [
        'no_issuer' => 'quien la dejó preparada ya no puede emitir facturas.',
        'not_issued' => 'No se pudo emitir sola: :reason',
        'not_sent' => 'Emitida, pero no se pudo enviar sola: :reason',
    ],

    'scheduled' => [
        'errors' => [
            'future' => 'La fecha de emisión programada tiene que ser posterior a hoy.',
            'not_allowed' => 'Programar es emitir más tarde: hace falta el permiso para emitir facturas.',
        ],
        'flash' => [
            'scheduled' => 'Programada: se emitirá sola el :date.',
            'scheduled_send' => 'Programada: se emitirá y se enviará sola el :date.',
            'unscheduled' => 'Ya no está programada: queda como borrador.',
        ],
        'run' => [
            'inactive' => 'La emisión no está encendida de verdad (o está en modo de prueba): las programadas no se emiten solas.',
            'done' => 'Programadas: :issued emitidas y :failed sin emitir.',
        ],
    ],

    'work' => [
        'no_task' => 'Sin tarea',
        'no_person' => 'Sin persona',
        'no_type' => 'Sin tipo de tarea',
        'hours_of_project' => 'Horas del proyecto',
        'month' => ':month de :year',
        'range' => 'del :from al :to',
        'bank_line' => 'Bolsa de horas :hoursh :month :year · :project',
        'fee_line' => 'Fee de :month de :year · :project',
        'fixed_line' => ':project · Hito',
        'overage_line' => 'Exceso de la bolsa :bank · :period',
        'errors' => [
            'empty' => 'Elige al menos una cosa que facturar.',
            'taken' => 'Alguna de esas horas ya no se puede facturar: está en otra factura, ya no está aprobada o se ha bloqueado. Vuelve a abrir el panel.',
            'bank_taken' => 'Esa bolsa ya tiene una factura (o ya no tiene precio). Vuelve a abrir el panel.',
            'fee_taken' => 'Ese mes del fee ya está facturado. Vuelve a abrir el panel.',
            'fixed_amount' => 'El importe de un precio cerrado tiene que ser mayor que 0 y como mucho lo pendiente (:pending €).',
        ],
        'flash' => [
            'created' => 'Borrador creado con lo que tenía por facturar. Revísalo y emítelo.',
        ],
    ],

    'import' => [
        'title' => 'Recurrentes de Holded',
        'dry_run' => 'Prueba (--dry-run): no se ha creado nada.',
        'done' => 'Importadas :created recurrentes en pausa; :existing ya estaban y :failed no se han podido importar.',
        'no_api' => 'No se puede leer Holded: :reason',
        'columns' => ['Recurrente de Holded', 'Cliente', 'Periodicidad', 'Próxima', 'Base', 'Resultado'],
        'created' => 'Importada en pausa',
        'would_create' => 'Se importaría en pausa',
        'existing' => 'Ya importada',
        'no_client' => 'Sin cliente: el contacto «:contact» no está casado con ningún cliente',
        'no_periodicity' => 'No se entiende la periodicidad (:value)',
        'no_lines' => 'Sin líneas',
        'no_next' => 'Sin próxima fecha',
        'unknown_service' => 'Línea «:line»: el servicio «:code» no está en el catálogo (va sin servicio)',
        'unknown_tax' => 'Línea «:line»: el impuesto «:tax» no se reconoce (va con el de por defecto)',
        'name' => ':contact · :concept',
    ],

    'attention' => [
        'recurring_drafts' => 'Borradores de recurrentes por revisar',
        'scheduled_today' => 'Programadas para hoy',
        'auto_failed' => 'Facturas que no se pudieron emitir o enviar solas',
        'recurring_failed' => 'Recurrentes con un error al generar',
    ],

    'notifications' => [
        'generated' => 'La recurrente «:name» ha generado su factura de :period.',
    ],
];
