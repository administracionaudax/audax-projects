<?php

/*
|--------------------------------------------------------------------------
| Previsión (docs/PLAN-CARGAS.md, Nivel 2; D-280 a D-289): textos del backend
|--------------------------------------------------------------------------
| Los del frontend van en lang/ui/forecast.json.
*/

return [
    'enums' => [
        'confidence' => [
            'tentative' => 'Posible',
            'firm' => 'Segura',
        ],
        'status' => [
            'open' => 'Abierto',
            'confirmed' => 'Confirmado',
            'lost' => 'Perdido',
            'linked' => 'Vinculado',
        ],
        'mode' => [
            'total' => 'Horas en total',
            'per_day' => 'Horas al día',
            'percent' => '% de dedicación',
            'monthly' => 'Horas al mes',
        ],
    ],

    'attributes' => [
        'name' => 'nombre',
        'client_id' => 'cliente',
        'prospect_name' => 'cliente nuevo',
        'color' => 'color',
        'description' => 'descripción',
        'owner_user_id' => 'responsable',
        'confidence' => 'seguridad',
        'start_date' => 'inicio',
        'end_date' => 'fin',
        'estimated_minutes' => 'estimación',
        'estimated_amount' => 'importe estimado',
        'reason' => 'motivo',
        'project_id' => 'proyecto',
        'copy_allocations' => 'copiar las asignaciones',
        'user_id' => 'persona',
        'department_id' => 'departamento',
        'mode' => 'modo',
        'minutes' => 'horas',
        'percent' => 'porcentaje',
        'note' => 'nota',
    ],

    'errors' => [
        'person_or_gap' => 'Elige una persona o un departamento (hueco), uno de los dos.',
        'person_not_assignable' => 'Solo se pueden asignar horas a personas activas de la plantilla o a colaboradores externos.',
        'department_missing' => 'Ese departamento no existe.',
        'not_a_gap' => 'Solo se puede pasar a una persona una asignación de un departamento (hueco).',
        'mode' => 'Elige cómo se reparten las horas.',
        'minutes' => 'Escribe las horas (como mucho 99.999 h).',
        'percent' => 'El porcentaje va del 1 al :max %.',
        'start_date' => 'Pon la fecha de inicio.',
        'end_required' => 'Pon la fecha de fin (solo las horas al mes pueden no tenerla).',
        'end_before_start' => 'La fecha de fin no puede ser anterior a la de inicio.',
        'too_long' => 'Una asignación dura como mucho tres años.',
        'frozen' => 'Este previsto está vinculado o perdido, o el proyecto está archivado: sus asignaciones no se pueden cambiar.',
        'client_or_prospect' => 'Elige un cliente o escribe el nombre del cliente nuevo.',
        'name' => 'Escribe el nombre del proyecto previsto.',
        'confirmed_is_firm' => 'Un previsto confirmado es siempre seguro.',
        'status_change' => 'El previsto ya no está en un estado que lo permita.',
        'no_prospect' => 'Este previsto no tiene un cliente nuevo que crear: elige el cliente.',
        'client_exists' => 'Ya hay un cliente que se llama así: elígelo en la lista.',
        'client_forbidden' => 'No puedes crear clientes: pide que lo creen o elige uno que exista.',
        'linked_cannot_delete' => 'Un previsto vinculado no se borra: es la línea base de su proyecto. Desvincúlalo antes.',
        'cannot_link' => 'Solo se vincula un previsto abierto o confirmado.',
        'project_archived' => 'No se puede vincular con un proyecto archivado.',
        'project_taken' => 'Ese proyecto ya está vinculado con otro previsto.',
        'not_linked' => 'Este previsto no está vinculado.',
        'project_forbidden' => 'No gestionas ese proyecto.',
    ],

    'history' => [
        'gap' => 'hueco de :department',
    ],

    'flash' => [
        'created' => 'Proyecto previsto creado.',
        'updated' => 'Cambios guardados.',
        'deleted' => 'Proyecto previsto borrado.',
        'confirmed' => 'Proyecto previsto confirmado.',
        'lost' => 'Proyecto previsto marcado como perdido.',
        'reopened' => 'Proyecto previsto reabierto.',
        'linked' => 'Vinculado con :project.',
        'project_created' => 'Proyecto :project creado y vinculado.',
        'unlinked' => 'Vínculo deshecho.',
        'allocation_created' => 'Asignación añadida.',
        'allocation_updated' => 'Asignación guardada.',
        'allocation_deleted' => 'Asignación borrada.',
        'allocation_assigned' => 'Asignada a :name.',
    ],
];
