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
        'people' => 'Registro de jornada',
        'day_plan' => 'Plan del día',
        'weeklies' => 'Weekly',
        'suggestions' => 'Sugerencias',
        'hour_banks' => 'Bolsas de horas',
        'absences' => 'Ausencias',
        'chat' => 'Chat',
        'reports' => 'Informes',
        'system' => 'Sistema',
    ],

    'events' => [
        'people' => [
            'clock_in_missing' => ['label' => 'No has fichado la entrada', 'description' => 'Pasado tu margen de entrada (o las 10:00, si tu jornada no tiene margen), si aún no has fichado en un día con jornada.'],
            'clock_out_missing' => ['label' => 'No has fichado la salida', 'description' => 'Cuando ya ha pasado tu hora de salida prevista y sigues trabajando según el registro.'],
            'workday_unclosed' => ['label' => 'Jornada sin cerrar', 'description' => 'A la mañana siguiente, si un día se quedó sin salida o sin ningún fichaje: para que propongas la corrección.'],
            'correction_requested' => ['label' => 'Correcciones que esperan tu conformidad', 'description' => 'Cuando alguien propone una corrección de un registro que te toca aceptar o rechazar (el tuyo o el de tu equipo).'],
            'correction_accepted' => ['label' => 'Corrección aceptada', 'description' => 'Cuando la otra parte acepta una corrección que has propuesto.'],
            'correction_disputed' => ['label' => 'Corrección en discrepancia', 'description' => 'Cuando una corrección que has propuesto se rechaza o se queda 7 días sin respuesta.'],
            'month_close_ready' => ['label' => 'Resumen del mes para confirmar', 'description' => 'El día 1, tu resumen del mes anterior (y si cambia antes de que lo confirmes). Es obligatorio: es la copia de tu registro.'],
            'month_close_reminder' => ['label' => 'Recordatorio de confirmar el mes', 'description' => 'A los 3 y a los 7 días, si aún no has confirmado tu resumen del mes.'],
            'month_close_reopened' => ['label' => 'Mes desconfirmado', 'description' => 'Cuando tu responsable o RR. HH. desconfirma tu mes para corregirlo, con su motivo. Obligatorio.'],
            'month_close_disagreed' => ['label' => 'Desacuerdos con el resumen del mes', 'description' => 'Cuando alguien de tu equipo no está de acuerdo con su resumen del mes.'],
            'overtime_weekly_summary' => ['label' => 'Resumen semanal de tus horas extra', 'description' => 'Los lunes, si la semana anterior hiciste horas extra o tienes exceso sin clasificar. Obligatorio (art. 35.5 ET).'],
            'overtime_cap' => ['label' => 'Tope anual de horas extra', 'description' => 'Cuando alguien de tu equipo pasa de 60 h o llega a las 80 h de horas extra del año. Obligatorio.'],
            'document_published' => ['label' => 'Documentos de RR. HH. para leer', 'description' => 'Cuando RR. HH. publica una versión nueva del documento del registro de jornada o de la política de desconexión.'],
            'integrity_broken' => ['label' => 'Comprobación del registro de jornada', 'description' => 'Cuando la comprobación nocturna del registro encuentra algo que no cuadra. Obligatorio.'],
        ],
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
            'week_reminder' => ['label' => 'Recordatorio de los viernes', 'description' => 'Los viernes, si aún no has enviado la semana de horas o la weekly: un solo aviso con lo que te falte.'],
            'timer_long' => ['label' => 'Temporizador encendido demasiado tiempo', 'description' => 'Cuando tu temporizador lleva muchas horas en marcha.'],
        ],
        'day_plan' => [
            'reminder' => ['label' => 'Recordatorio del plan del día', 'description' => 'A la hora límite (por defecto, las 8:30), si aún no has escrito tu plan de hoy. Solo los días que trabajas: nunca en festivos, ausencias ni con «Estoy fuera». También cuando tu responsable te lo recuerda.'],
            'commented' => ['label' => 'Comentarios en tu plan del día', 'description' => 'Cuando tu responsable o un admin comenta una de tus líneas.'],
        ],
        'weeklies' => [
            'reminder' => ['label' => 'Recordatorios de la weekly', 'description' => 'Los que programa quien gestiona la Weekly y los «Recordar», si aún no has enviado la tuya. Cada recordatorio sale por el canal que elige quien lo programa; aquí puedes quitar los que no quieras.'],
            'closed' => ['label' => 'Weekly cerrada', 'description' => 'Cuando se cierra la semana, con el enlace al informe.'],
            'deadline_changed' => ['label' => 'Plazo de la weekly cambiado', 'description' => 'Cuando se amplía o cambia el plazo de la semana y aún no has enviado tu weekly.'],
        ],
        'suggestions' => [
            'status_changed' => ['label' => 'Cambia el estado de tu sugerencia', 'description' => 'Cuando quien gestiona la ayuda mueve una sugerencia tuya (planificada, en desarrollo, beta…), con su nota si la hay.'],
            'replied' => ['label' => 'Te responden en las sugerencias', 'description' => 'Cuando alguien comenta una sugerencia tuya o responde a un comentario tuyo.'],
            'mentioned' => ['label' => 'Te mencionan en las sugerencias', 'description' => 'Cuando alguien te menciona en una sugerencia o en un comentario.'],
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
            'second_approval' => ['label' => 'Vacaciones por aprobar (RR. HH.)', 'description' => 'Cuando unas vacaciones esperan el segundo nivel de aprobación.'],
            'cancellation_requested' => ['label' => 'Cancelaciones pedidas', 'description' => 'Cuando alguien de tu equipo pide cancelar una ausencia aprobada.'],
            'cancellation_decided' => ['label' => 'Cancelación decidida', 'description' => 'Cuando aceptan o rechazan la cancelación que has pedido.'],
            'balance_expiring' => ['label' => 'Saldo a punto de caducar', 'description' => 'Un mes antes de que caduquen días u horas que te quedan sin disfrutar.'],
            'document_missing' => ['label' => 'Justificante pendiente', 'description' => 'Cuando una ausencia que pide justificante ya ha empezado y aún no lo has subido.'],
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
    // Desde la 10.5 (D-200) lleva también la weekly pendiente: un solo aviso los viernes.
    'reminder' => [
        'title' => 'Recuerda enviar tu semana',
        'title_both' => 'Recuerda enviar tu semana y tu weekly',
        'title_weekly' => 'Recuerda enviar tu weekly',
        'body' => 'Llevas :logged de :capacity imputadas en la semana del :week.',
        'body_returned' => 'Te devolvieron la semana del :week para corregirla: llevas :logged de :capacity imputadas.',
        'body_weekly' => 'Tu weekly de la :cycle sigue pendiente (plazo: :deadline).',
        'weekly_action' => 'Escribir mi weekly',
    ],
];
