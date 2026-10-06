<?php

/*
|--------------------------------------------------------------------------
| Plan del día (docs/PLAN-CARGAS.md, Nivel 1; D-250 a D-256): textos del backend
|--------------------------------------------------------------------------
| Los del frontend van en lang/ui/day-plan.json.
*/

return [
    'enums' => [
        'status' => [
            'pending' => 'Pendiente',
            'done' => 'Hecha',
            'not_done' => 'No hecha',
            'carried' => 'Pasada a otro día',
        ],
    ],

    'attributes' => [
        'date' => 'día',
        'text' => 'texto',
        'client_id' => 'cliente',
        'project_id' => 'proyecto',
        'task_id' => 'tarea',
        'planned_minutes' => 'horas previstas',
        'note' => 'nota',
        'body' => 'comentario',
        'deadline' => 'hora límite del plan del día',
        'editable_days' => 'días que se pueden cerrar',
    ],

    'dates' => [
        'today' => 'hoy',
        'tomorrow' => 'mañana',
        'on' => 'el :date',
    ],

    'errors' => [
        'text_required' => 'Escribe qué vas a hacer.',
        'text_max' => 'La línea no puede pasar de :max caracteres.',
        'minutes' => 'Las horas previstas deben estar entre 0:01 y 24:00.',
        'too_many' => 'Un día no puede tener más de :max líneas.',
        'not_writable' => 'Solo puedes escribir el plan de hoy y de los días que vienen, hasta el :until.',
        'read_only' => 'Este día ya está cerrado: sus líneas son de solo lectura.',
        'not_yours' => 'Solo puedes cambiar las líneas de tu plan.',
        'status' => 'Ese estado no es válido.',
        'already_carried' => 'Esta línea ya se ha pasado a otro día: cámbiala allí.',
        'carry_done' => 'Una línea hecha no se pasa a otro día.',
        'carry_same_day' => 'Elige otro día.',
        'task' => 'Esa tarea no existe o no puedes verla.',
        'project' => 'Ese proyecto no existe o no puedes verlo.',
        'client' => 'Ese cliente no existe.',
        'choose_task' => 'Elige en qué tarea van las horas de esta línea.',
        'no_project' => 'Para crear la tarea, la línea necesita un proyecto. Elige una tarea.',
        'cannot_create_task' => 'No puedes crear tareas en :project. Elige una tarea.',
        'no_bank' => ':project tiene varias bolsas: elige una tarea para saber en cuál van las horas.',
        'log_planned' => 'Solo se imputa lo previsto de una línea hecha, con tarea y horas previstas.',
        'already_logged' => 'Esta línea ya tiene horas imputadas.',
        'entry_not_yours' => 'Solo puedes vincular tus horas.',
        'entry_locked' => 'Esas horas están bloqueadas (facturadas): no se pueden vincular.',
        'entry_not_linkable' => 'Solo se vinculan tus horas de ese día que no estén en otra línea.',
    ],

    'flash' => [
        'deleted' => 'Línea borrada.',
        'carried' => 'Línea pasada a :date.',
        'carried_many' => '{1} 1 línea pasada a :date.|[2,*] :count líneas pasadas a :date.',
        'not_done_many' => '{1} 1 línea marcada como no hecha.|[2,*] :count líneas marcadas como no hechas.',
        'from_tasks' => '{1} 1 tarea añadida a tu día (:date).|[2,*] :count tareas añadidas a tu día (:date).',
        'from_tasks_none' => 'Esas tareas ya estaban en tu plan de ese día.',
        'commented' => 'Comentario publicado.',
        'logged' => 'Imputadas :time en «:text».',
        'logged_many' => '{0} No había líneas hechas con horas previstas por imputar.|{1} Imputada 1 línea (:time).|[2,*] Imputadas :count líneas (:time).',
        'logged_partial' => 'Imputadas :logged líneas. No se han podido imputar: :failed',
        'linked' => '{0} La línea se ha quedado sin horas vinculadas.|{1} 1 entrada de horas vinculada a la línea.|[2,*] :count entradas de horas vinculadas a la línea.',
        'comment_deleted' => 'Comentario borrado.',
    ],

    // «Recordar» desde Equipo hoy (D-252).
    'remind' => [
        'sent' => 'Se le ha recordado a :name que escriba su plan.',
        'preview' => 'Modo de prueba: no se avisa a nadie.',
        'has_plan' => ':name ya ha escrito su plan de hoy.',
        'off' => ':name no trabaja hoy: no se le recuerda.',
        'already' => 'A :name ya se le ha recordado hoy.',
        'no_channel' => 'No se ha enviado: :name tiene desactivado este aviso.',
    ],

    'notifications' => [
        'reminder_title' => 'Aún no has escrito tu plan de hoy',
        'reminder_by_title' => ':name te pide que escribas tu plan de hoy',
        'reminder_body' => 'Escribe en Mi día lo que vas a hacer hoy, en líneas: menos de un minuto.',
        'commented_title' => ':name ha comentado tu plan del día',
        'commented_body' => '«:line»: :comment',
    ],
];
