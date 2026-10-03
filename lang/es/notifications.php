<?php

/*
| Notificaciones (SPEC §13, D-073): el email genérico de AppNotification y los nombres de los
| grupos, eventos y canales de /ajustes/notificaciones (NotificationPreferences::forUser); el
| guardado de esa página (settings), el resumen diario por email (digest) y el recordatorio de
| enviar la semana (reminder).
| Las claves de los eventos siguen su kind(): «task.assigned» → events.task.assigned.
*/

return [
    'mail' => [
        'greeting' => 'Hola, :name:',
        'greeting_anonymous' => 'Hola:',
        'action' => 'Abrir en Audax Proyectos',
        'salutation' => "Un saludo,\n:company",
    ],

    'channels' => [
        'app' => 'En la app',
        'email' => 'Email',
        'push' => 'Avisos del navegador',
    ],

    'groups' => [
        'tasks' => 'Tareas',
        'time' => 'Horas',
        'hour_banks' => 'Bolsas de horas',
        'absences' => 'Ausencias',
        'chat' => 'Chat',
        'reports' => 'Informes',
        'system' => 'Sistema',
    ],

    'events' => [
        'task' => [
            'assigned' => ['label' => 'Te asignan una tarea', 'description' => 'Cuando alguien te hace responsable de una tarea.'],
            'mentioned' => ['label' => 'Te mencionan en una tarea', 'description' => 'Cuando alguien te menciona en la descripción o en un comentario.'],
            'commented' => ['label' => 'Comentarios en tareas que sigues', 'description' => 'Cuando alguien comenta una tarea que sigues.'],
            'status_changed' => ['label' => 'Cambios de estado en tareas que sigues', 'description' => 'Cuando una tarea que sigues cambia de estado.'],
            'due' => ['label' => 'Tareas que vencen', 'description' => 'Tus tareas que vencen mañana o ya han vencido.'],
        ],
        'time' => [
            'returned' => ['label' => 'Semana devuelta', 'description' => 'Cuando te devuelven la semana para corregirla.'],
            'approved' => ['label' => 'Semana aprobada', 'description' => 'Cuando te aprueban la semana.'],
            'week_reminder' => ['label' => 'Recordatorio de enviar la semana', 'description' => 'Los viernes, si aún no has enviado la semana.'],
            'timer_long' => ['label' => 'Temporizador encendido demasiado tiempo', 'description' => 'Cuando tu temporizador lleva muchas horas en marcha.'],
        ],
        'hour_bank' => [
            'threshold' => ['label' => 'Bolsas que llegan a un umbral', 'description' => 'Cuando una bolsa que gestionas llega al 75, 90 o 100 %.'],
            'overage' => ['label' => 'Horas en exceso', 'description' => 'Cuando una bolsa agotada sigue recibiendo horas (como mucho un aviso al día).'],
        ],
        'absence' => [
            'requested' => ['label' => 'Ausencias por aprobar', 'description' => 'Cuando alguien de tu equipo pide una ausencia.'],
            'approved' => ['label' => 'Ausencia aprobada', 'description' => 'Cuando te aprueban una ausencia.'],
            'rejected' => ['label' => 'Ausencia rechazada', 'description' => 'Cuando te rechazan una ausencia.'],
            'updated' => ['label' => 'Ausencia modificada', 'description' => 'Cuando otra persona cambia una ausencia tuya.'],
            'cancelled' => ['label' => 'Ausencia anulada', 'description' => 'Cuando se anula una ausencia tuya o de tu equipo.'],
        ],
        'chat' => [
            'direct' => ['label' => 'Mensajes directos', 'description' => 'Cuando alguien te escribe por mensaje directo.'],
            'mention' => ['label' => 'Menciones en el chat', 'description' => 'Cuando te mencionan o escriben @todos en una conversación (salvo las silenciadas).'],
        ],
        'reports' => [
            'weekly_digest' => ['label' => 'Resumen semanal de productividad', 'description' => 'Los lunes, la ocupación de tu equipo en la semana anterior.'],
            'schedule_paused' => ['label' => 'Envíos programados en pausa', 'description' => 'Cuando un envío programado de un informe se pausa porque ya no se puede enviar.'],
        ],
        'system' => [
            'transcriptions_failing' => ['label' => 'Transcripciones que fallan', 'description' => 'Audios del chat que no se consiguen transcribir.'],
            'disk_space' => ['label' => 'Espacio en disco', 'description' => 'Cuando el disco o los adjuntos pasan del umbral.'],
            'backup_failed' => ['label' => 'Copias de seguridad', 'description' => 'Cuando falla una copia o su prueba de restauración.'],
        ],
    ],

    // /ajustes/notificaciones (App\Http\Controllers\Settings\NotificationSettingsController).
    'settings' => [
        'saved' => 'Preferencias de notificación guardadas.',
        'attributes' => [
            'events' => 'avisos',
            'daily_digest' => 'resumen diario',
        ],
        'errors' => [
            'channels' => 'Los canales solo pueden ser «En la app», «Email» y «Avisos del navegador».',
            'value' => 'Cada canal tiene que estar activado o desactivado.',
        ],
    ],

    // Resumen diario por email (notifications:daily-digest, App\Notifications\DailyDigestNotification).
    'digest' => [
        'subject' => '{1} Resumen diario: 1 aviso sin leer|[2,*] Resumen diario: :count avisos sin leer',
        'intro' => '{1} Este es el aviso de las últimas :hours horas que aún no has leído:|[2,*] Estos son los :count avisos de las últimas :hours horas que aún no has leído:',
        'group' => ':group (:count)',
        'more' => '{1} y 1 más|[2,*] y :count más',
        'action' => 'Ver mis notificaciones',
        'settings_hint' => 'Recibes este resumen porque lo tienes activado: mientras lo esté, estos avisos no te llegan en emails sueltos.',
        'settings_link' => 'Cambiar mis preferencias de notificación',
    ],

    // Recordatorio de enviar la semana (time:remind-week, App\Notifications\Time\WeekSubmissionReminder).
    'reminder' => [
        'title' => 'Recuerda enviar tu semana',
        'body' => 'Llevas :logged de :capacity imputadas en la semana del :week.',
        'body_returned' => 'Te devolvieron la semana del :week para corregirla: llevas :logged de :capacity imputadas.',
    ],
];
