<?php

/*
| Mensajes del área de bolsas de horas (backend). Se usan con __('hour_banks.…'). Los textos de
| la interfaz React están en lang/ui/hour-banks.json.
*/

return [
    'flash' => [
        'created' => 'Bolsa «:name» creada.',
        'updated' => 'Bolsa guardada.',
        'renewed' => 'Bolsa renovada. Las horas imputadas se quedan en la anterior.',
        'renewed_with_tasks' => '{1} Bolsa renovada: se ha movido 1 tarea abierta. Las horas imputadas se quedan en la anterior.|[2,*] Bolsa renovada: se han movido :count tareas abiertas. Las horas imputadas se quedan en la anterior.',
        'closed' => 'Bolsa cerrada. Saldo sin consumir: :remaining.',
        'reopened' => 'Bolsa reabierta.',
        'deleted' => 'Bolsa «:name» eliminada.',
        'deleted_renewal' => 'Bolsa «:name» eliminada. «:previous» deja de estar renovada y vuelve a estar :status.',
    ],

    'errors' => [
        'not_hour_bank_project' => 'Este proyecto no trabaja con bolsas de horas.',
        'renewed_not_editable' => 'Una bolsa renovada no se puede editar: su histórico queda como estaba.',
        'not_open' => 'La bolsa «:bank» está :status: solo se pueden renovar o cerrar bolsas activas o agotadas.',
        'not_closed' => 'Solo se puede reabrir una bolsa cerrada que no se haya renovado.',
        'not_due' => 'Solo se puede renovar una bolsa agotada o que haya llegado al :threshold % de consumo.',
        'has_renewal' => 'La bolsa ya se ha renovado: no se puede eliminar sin romper el histórico. Si hace falta, elimina antes la bolsa que la renueva.',
        'closed_total' => 'La bolsa está cerrada: para cambiar el total, un administrador tiene que reabrirla antes.',
        'has_tasks' => 'La bolsa tiene tareas. Muévelas a otra bolsa antes de eliminarla.',
        'has_time' => 'La bolsa tiene horas imputadas: no se puede eliminar. Puedes cerrarla.',
        'end_before_start' => 'La fecha de fin no puede ser anterior a la de inicio.',
        'total_range' => 'El total debe estar entre 0:01 y :max.',
    ],

    'attributes' => [
        'name' => 'nombre',
        'department_id' => 'departamento',
        'total_minutes' => 'total de horas',
        'start_date' => 'fecha de inicio',
        'end_date' => 'fecha de fin',
        'overage_policy' => 'política de exceso',
        'hourly_rate' => 'tarifa por hora',
        'price_amount' => 'precio',
        'invoice_reference' => 'referencia de factura',
        'notes' => 'notas',
        'move_open_tasks' => 'mover las tareas abiertas',
    ],

    'notifications' => [
        'threshold' => [
            'title' => 'La bolsa «:bank» ha llegado al :threshold %',
            'body' => ':project. Consumidas :consumed de :total; quedan :remaining.',
        ],
        'overage' => [
            'title' => 'Horas en exceso en la bolsa «:bank»',
            'body' => ':project. Se han registrado :added de exceso; la bolsa suma :overage de exceso sobre :total.',
        ],
    ],

    'mail' => [
        'greeting' => 'Hola, :name:',
        'action' => 'Ver la bolsa',
        'threshold_hint' => 'Si hace falta, renueva la bolsa o revisa las tareas planificadas.',
        'overage_hint' => 'Estas horas se facturan aparte o se descuentan de la renovación.',
        'salutation' => "Un saludo,\n:company",
    ],
];
