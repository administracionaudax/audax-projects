<?php

/*
| Mensajes de la administración (SPEC §14). Se usan con __('admin.…'). Español de España, tuteo.
| Los textos de la interfaz React del área están en lang/ui/admin.json.
*/

return [
    'errors' => [
        'palette' => 'Elige un color de la paleta.',
    ],

    'attributes' => [
        'name' => 'nombre',
        'email' => 'correo electrónico',
        'role' => 'rol',
        'department' => 'departamento',
        'hourly_cost' => 'coste por hora',
        'default_hourly_rate' => 'tarifa por hora',
        'assignee' => 'persona',
        'task' => 'tarea',
        'owner' => 'gestor principal',
        'project' => 'proyecto',
        'valid_from' => 'fecha de inicio',
        'week' => 'jornada',
        'color' => 'color',
        'managers' => 'responsables',
        'icon' => 'icono',
        'category' => 'categoría',
        'is_default' => 'estado por defecto',
        'replacement' => 'estado de reemplazo',
        'company_name' => 'nombre de la empresa',
        'timer_warning_hours' => 'aviso del temporizador',
        'thresholds' => 'umbrales de alerta',
        'max_attachment_mb' => 'tamaño máximo de los adjuntos',
        'default_work_minutes' => 'jornada por defecto',
    ],

    'users' => [
        'invited' => 'Invitación enviada a :email.',
        'invitation_resent' => 'Invitación reenviada a :email.',
        'updated' => 'Cambios guardados.',
        'deactivated' => ':name ya no tiene acceso.',
        'reactivated' => ':name vuelve a tener acceso.',
        'summary' => [
            'reassigned' => '{1} Se ha reasignado :count tarea.|[2,*] Se han reasignado :count tareas.',
            'unassigned' => '{1} :count tarea queda sin asignar.|[2,*] :count tareas quedan sin asignar.',
            'timer_stopped' => 'Su temporizador se ha parado y se han imputado :minutes.',
            'timer_discarded' => 'Su temporizador no se ha podido imputar y se ha descartado: :reason',
            'timer_too_short' => 'Su temporizador duraba menos del redondeo y se ha descartado.',
            'departments' => '{1} Deja de ser responsable de :count departamento.|[2,*] Deja de ser responsable de :count departamentos.',
            'projects' => '{1} :count proyecto tiene un gestor principal nuevo.|[2,*] :count proyectos tienen un gestor principal nuevo.',
        ],
        'errors' => [
            'client_user' => 'Las personas del portal de clientes se gestionan desde su cliente.',
            'admin_only' => 'Solo un administrador puede gestionar a otro administrador.',
            'grant_admin' => 'Solo un administrador puede dar el rol de administración.',
            'self_demote' => 'No puedes quitarte tu propio rol de administración. Pídeselo a otro administrador.',
            'last_admin' => 'Es la única persona con el rol de administración activa: antes, da ese rol a otra persona.',
            'already_inactive' => 'Esta persona ya está desactivada.',
            'self_deactivate' => 'No puedes desactivar tu propia cuenta.',
            'email_taken' => 'Ya hay una persona con este correo electrónico.',
            'role' => 'Elige un rol: administración, responsable de departamento o empleado.',
            'assignee' => 'Elige una persona activa de la agencia (que no sea la que se da de baja).',
            'invite_inactive' => 'Esta persona está desactivada: reactívala antes de enviarle la invitación.',
        ],
    ],

    'schedules' => [
        'created' => 'Jornada guardada.',
        'updated' => 'Jornada actualizada.',
        'deleted' => 'Versión de la jornada eliminada.',
        'must_follow' => 'La nueva jornada tiene que empezar después del :date, que es cuando empieza la anterior.',
        'from_must_be_future' => 'Una versión que se edita tiene que empezar después de hoy.',
        'new_must_be_future' => 'Una versión nueva tiene que empezar después de hoy: la jornada de los días pasados no se cambia.',
        'first_not_past' => 'La jornada no puede empezar antes de hoy.',
        'only_latest' => 'Solo se puede cambiar la última versión de la jornada.',
        'already_started' => 'Esta versión ya ha empezado: no se puede cambiar. Crea una versión nueva.',
        'day_range' => 'Cada día tiene que estar entre 0:00 y 24:00.',
    ],

    'departments' => [
        'created' => 'Departamento creado.',
        'updated' => 'Departamento actualizado.',
        'deleted' => 'Se ha eliminado el departamento :name.',
        'errors' => [
            'name_taken' => 'Ya hay un departamento con este nombre.',
            'managers' => 'Los responsables tienen que ser personas activas con el rol de responsable o de administración.',
            'has_people' => 'No se puede eliminar: tiene personas activas. Cámbialas antes de departamento.',
            'has_inactive_people' => 'No se puede eliminar: tiene personas desactivadas. Cámbialas antes de departamento desde su ficha.',
            'has_banks' => 'No se puede eliminar: tiene bolsas de horas abiertas.',
        ],
    ],

    'task_types' => [
        'created' => 'Tipo de tarea creado.',
        'updated' => 'Tipo de tarea actualizado.',
        'deleted' => 'Se ha eliminado el tipo :name.',
        'deactivated_in_use' => 'Hay tareas de tipo :name, así que no se elimina: se ha desactivado y ya no se ofrecerá.',
        'errors' => [
            'name_taken' => 'Ya hay un tipo de tarea con este nombre.',
            'icon' => 'Elige un icono de la lista.',
        ],
    ],

    'statuses' => [
        'created' => 'Estado creado.',
        'updated' => 'Estado actualizado.',
        'deleted' => 'Se ha eliminado el estado :name.',
        'deleted_moved' => '{1} Se ha eliminado el estado :name y su tarea ha pasado a «:replacement».|[2,*] Se ha eliminado el estado :name y sus :count tareas han pasado a «:replacement».',
        'errors' => [
            'name_taken' => 'Ya hay un estado con este nombre.',
            'default_done' => 'El estado por defecto es el de las tareas nuevas: no puede ser de la categoría «Hecha».',
            'needs_default' => 'Tiene que haber un estado por defecto: marca otro como predeterminado y este dejará de serlo.',
            'min_categories' => 'Tiene que quedar al menos un estado «Por hacer» y otro «Hecha».',
            'delete_default' => 'No se puede eliminar el estado por defecto: marca antes otro como predeterminado.',
            'replacement_required' => '{1} Hay :count tarea en este estado: elige a qué estado pasa.|[2,*] Hay :count tareas en este estado: elige a qué estado pasan.',
            'replacement_same' => 'Elige un estado distinto del que eliminas.',
        ],
    ],

    'settings' => [
        'saved' => 'Ajustes guardados.',
        'errors' => [
            'rounding' => 'Elige un redondeo de 1, 5, 10, 15 o 30 minutos.',
            'thresholds_distinct' => 'Los umbrales no se pueden repetir.',
            'thresholds_range' => 'Cada umbral tiene que ser un número entero entre 1 y 200.',
            'thresholds_count' => 'Indica entre 1 y 5 umbrales.',
        ],
    ],

    'invitation' => [
        'subject' => 'Te damos la bienvenida a :company',
        'greeting' => 'Hola, :name:',
        'intro' => ':company te ha dado acceso a Audax Proyectos, donde gestionamos las tareas, las horas y los proyectos del estudio.',
        'intro_by' => ':inviter te ha dado acceso a Audax Proyectos, la herramienta de :company para gestionar las tareas, las horas y los proyectos del estudio.',
        'instructions' => 'Para entrar, elige tu contraseña con este botón:',
        'action' => 'Elegir mi contraseña',
        'expires' => 'El enlace caduca en :days días y solo sirve una vez. Si caduca, pide que te reenvíen la invitación.',
        'ignore' => 'Si no esperabas este correo, puedes ignorarlo.',
        'salutation' => 'Un saludo, el equipo de :company',
    ],
];
