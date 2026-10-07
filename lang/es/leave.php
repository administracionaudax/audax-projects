<?php

/*
| Vacaciones y permisos (Fase 11, R3; PLAN-FASE-11 §7.6; D-360 a D-379): catálogo de tipos, saldos,
| justificantes, segundo nivel, «Pedir cancelación», calendario laboral e informes.
| Se usan con __('leave.…').
*/

return [
    'units' => [
        'days' => '{1} :value día|[0,*] :value días',
        'hours' => ':value h',
    ],

    'reasons' => [
        'accrual' => 'Asignación anual de :year: :amount.',
        'recalculated' => 'Recálculo de la asignación de :year por un cambio del alta, la baja, la jornada o el tipo: ahora :amount.',
        'opening' => 'Saldo inicial desde Woffu a :date.',
    ],

    'errors' => [
        'kind' => 'Ese movimiento no se puede anotar a mano.',
        'reason' => 'Explica el motivo (5 caracteres como mínimo): queda en el libro de saldos.',
        'zero' => 'Indica una cantidad distinta de cero.',
        'expiry_before' => 'La caducidad no puede ser anterior a la fecha desde la que vale.',
        'carry_range' => 'La nueva caducidad tiene que ser posterior a la de ahora y no pasar del :max (18 meses tras el final del año, art. 38.3 ET).',
        'type_missing' => 'Elige un tipo de ausencia.',
        'type_inactive' => '«:type» ya no se puede pedir.',
        'slot_days' => '«:type» se pide por días, sin franja horaria.',
        'slot_incomplete' => 'Indica la hora de inicio y la de fin.',
        'slot_order' => 'La hora de fin tiene que ser posterior a la de inicio.',
        'slot_unavailable' => 'Las ausencias por horas con franja aún no están disponibles.',
        'blocked' => 'Esas fechas están bloqueadas para las vacaciones (:name, :period). Habla con RR. HH. si necesitas cogerlas.',
        'no_balance' => 'No tienes saldo suficiente de «:type»: pides :requested y en esas fechas te quedan :available.',
        'second_level_only' => 'Ya tiene el primer nivel de aprobación: solo falta la de RR. HH.',
        'cancellation_reason' => 'Explica por qué quieres cancelarla.',
        'cancellation_unavailable' => 'Esta ausencia ya no admite pedir su cancelación.',
        'cancellation_not_pending' => 'Esta cancelación ya no está pendiente.',
        'too_many_documents' => 'Una ausencia admite :max justificantes como mucho. Borra alguno antes.',
        'second_approval_vacation' => 'El segundo nivel de aprobación solo se puede activar en las vacaciones.',
        'carry_over_format' => 'Usa el formato MM-DD (por ejemplo, 03-31).',
        'carry_left' => 'De :year solo quedan :amount sin disfrutar.',
        'calendar_overlap' => 'Ya hay un día especial de ese tipo en esas fechas: :name.',
    ],

    'import' => [
        'unknown_person' => 'No hay ninguna persona de la plantilla con ese email.',
        'unknown_type' => 'No hay ningún tipo de ausencia con esa clave.',
        'bad_amount' => 'La cantidad no se entiende: en días, «12,5»; en horas, «16:00».',
        'bad_date' => 'La caducidad tiene que ser AAAA-MM-DD.',
    ],

    'warnings' => [
        'short_notice' => 'Empiezan antes de 2 meses: el Estatuto pide conocer las fechas de las vacaciones con 2 meses de antelación (art. 38.3 ET). Se pueden aprobar igual si hay acuerdo.',
        'notice' => 'Este permiso pide avisar con :days días de antelación.',
        'over_amount' => '«:type» da :amount:extra; pides más. Quien la aprueba decide.',
        'with_travel' => ' (y :amount más con desplazamiento)',
        'document' => 'Pide justificante: súbelo cuando lo tengas.',
        'unpaid_excess' => 'Pasas de las horas retribuidas del año: :amount serían sin retribuir.',
        'non_working_start' => 'Empieza en un día que no trabajas: el permiso se cuenta desde el primer día laborable (Tribunal Supremo).',
    ],

    'flash' => [
        'first_approved' => 'Primer nivel aprobado. Falta la aprobación de RR. HH.',
        'cancellation_requested' => 'Has pedido cancelar :type :period. Te avisaremos cuando lo decidan.',
        'cancellation_accepted' => 'Cancelación aceptada: la ausencia de :name queda cancelada.',
        'cancellation_rejected' => 'Cancelación rechazada: la ausencia de :name sigue aprobada.',
        'document_uploaded' => 'Justificante subido.',
        'document_deleted' => 'Justificante borrado.',
        'type_saved' => 'Tipo «:name» guardado.',
        'type_created' => 'Tipo «:name» creado.',
        'adjusted' => 'Movimiento anotado en el saldo de :name.',
        'carried_over' => 'Arrastre anotado en el saldo de :name.',
        'synced' => '{0} Las asignaciones ya estaban al día.|{1} Se ha anotado 1 asignación.|[2,*] Se han anotado :count asignaciones.',
        'calendar_day_saved' => 'Día especial guardado: :name.',
        'calendar_day_deleted' => 'Día especial borrado: :name.',
        'valencia_added' => '{0} Los festivos de València de :year ya estaban todos.|{1} Se ha añadido 1 festivo de València de :year.|[2,*] Se han añadido :count festivos de València de :year.',
    ],

    'notifications' => [
        'second_approval' => [
            'title' => 'Vacaciones de :name por aprobar (RR. HH.): :period',
            'body' => ':actor ya ha dado el primer nivel. Falta la aprobación de RR. HH.',
            'body_self' => 'Lo pide un responsable: solo falta la aprobación de RR. HH.',
        ],
        'cancellation_requested' => [
            'title' => ':name pide cancelar :phrase :period',
        ],
        'cancellation_accepted' => [
            'title' => 'Cancelación aceptada: :type :period',
            'body' => 'La ha aceptado :actor. Lo que gastaba vuelve a tu saldo.',
        ],
        'cancellation_rejected' => [
            'title' => 'Cancelación rechazada: :type :period',
            'body' => ':actor: «:comment». La ausencia sigue aprobada.',
        ],
        'document_missing' => [
            'title' => 'Falta el justificante de :type :period',
            'body' => 'Súbelo desde «Mis ausencias». Solo lo verán tú, RR. HH. y, si no es un dato de salud, tu responsable.',
        ],
        'expiring' => [
            'title' => 'Te caducan :amount de «:type» el :date',
            'body' => 'Pídelos antes de esa fecha o habla con RR. HH.',
        ],
    ],

    'activity' => [
        'leave_accrued' => 'Asignación anual',
        'leave_adjusted' => 'Ajuste de saldo',
        'leave_carried_over' => 'Arrastre de saldo',
        'document_uploaded' => 'Justificante subido',
        'document_downloaded' => 'Justificante descargado',
        'document_deleted' => 'Justificante borrado',
    ],

    'attributes' => [
        'leave_type_id' => 'tipo',
        'start_time' => 'hora de inicio',
        'end_time' => 'hora de fin',
        'reason' => 'motivo',
        'amount' => 'cantidad',
        'expires_on' => 'caducidad',
        'valid_from' => 'desde',
        'file' => 'justificante',
        'name' => 'nombre',
        'kind' => 'tipo de día',
    ],
];
