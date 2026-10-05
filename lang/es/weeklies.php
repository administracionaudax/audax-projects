<?php

/*
|--------------------------------------------------------------------------
| La Weekly (Fase 10, D-145 a D-150): textos del backend
|--------------------------------------------------------------------------
| Los del frontend van en lang/ui/weeklies.json.
*/

return [
    // Etiqueta de una semana, como en WeeklySync (WeeklyCalendar::label()).
    'cycle_label' => 'Semana :week (Lun :start - Vie :end)',

    'not_implemented' => 'Esta parte de la Weekly todavía no está disponible.',
    'module_disabled' => 'Este módulo está desactivado.',

    // Aviso de bienvenida al entrar (F-012).
    'welcome' => '¡Hola, :name! Bienvenido de nuevo.',

    // Avisos de las acciones de la entrega 10.2.
    'flash' => [
        'opened' => 'Semana abierta: :label.',
        'deadline_updated' => 'Plazo actualizado.',
        'deleted' => 'Weekly «:label» eliminada.',
        'deleted_and_opened' => 'Weekly «:label» eliminada. Se ha abierto la siguiente: :next.',
        'submitted' => 'Weekly enviada. ¡Gracias!',
        'resubmitted' => 'Weekly actualizada.',
        'exempted' => ':name queda exento de la weekly de esta semana.',
        'exemption_removed' => 'Exención quitada.',
        'waived' => 'Ya puedes rellenar y enviar tu weekly.',
        'waiver_undone' => 'Vuelves a estar exento esta semana.',
        'joined' => '{0} Ya eras miembro de esos proyectos.|{1} Te has unido a 1 proyecto.|[2,*] Te has unido a :count proyectos.',
        'left' => 'Has dejado el proyecto :project.',
    ],

    'validation' => [
        'deadline_required' => 'Indica el día del plazo.',
        'deadline_range' => 'El plazo tiene que estar entre el lunes de la semana y cuatro semanas después del viernes.',
        'entries' => 'La weekly no tiene un formato válido.',
        'client' => 'Uno de los clientes ya no existe.',
        'body_too_long' => 'Cada apunte puede tener como mucho :max caracteres.',
        'empty_submission' => 'Escribe algo en al menos un cliente antes de enviar.',
        'person' => 'Elige a una persona que participe en la weekly de esta semana.',
        'note_too_long' => 'La nota puede tener como mucho 500 caracteres.',
        'not_exempt' => 'No estás exento esta semana.',
        'projects' => 'Elige proyectos abiertos de clientes activos.',
        'manager_cannot_leave' => 'Gestionas este proyecto: no puedes dejarlo desde aquí.',
    ],

    // Dictado (F-049, F-050, F-171 y F-172).
    'dictation' => [
        'errors' => [
            'audio_type' => 'El audio no tiene un formato admitido.',
            'audio_too_big' => 'El audio no puede pasar de :max MB.',
            'duration' => 'Falta la duración del audio.',
            'too_long' => 'El dictado no puede durar más de :max.',
            'duration_mismatch' => 'El tamaño del audio no cuadra con su duración.',
        ],
    ],

    // «Autocompletar desde mis tareas y horas» (F-048).
    'autofill' => [
        'done' => 'Hecho: :task',
        'pending' => 'En curso: :task',
        'time' => '(:time)',
    ],

    'errors' => [
        'cycle_closed' => 'La semana ya está cerrada: la weekly es de solo lectura.',
        'not_participant' => 'No te toca enviar la weekly de esta semana.',
        'exempt' => 'Estás exento esta semana. Quita la exención para poder enviar.',
        'already_active' => 'Ya hay una semana activa.',
        'llm_not_configured' => 'La IA no está configurada (falta GEMINI_API_KEY).',
        'llm_unavailable' => 'La IA no responde ahora mismo. Inténtalo más tarde.',
        'llm_invalid_response' => 'La IA ha devuelto una respuesta que no se puede leer.',
        'tts_not_configured' => 'La locución no está configurada (falta GOOGLE_TTS_API_KEY).',
        'tts_unavailable' => 'El servicio de locución no responde ahora mismo.',
    ],

    // Plantillas de aviso por defecto (F-104 y F-105). Se editan en ajustes (setting
    // weekly_email_templates); variables {nombre}, {semana} y {weekly_url}.
    'templates' => [
        'automatic' => [
            'subject' => 'Recordatorio automático: weekly {semana}',
            'body' => "Hola {nombre},\n\nEste es un recordatorio automático para que completes tu weekly de la semana {semana}.\n\nEntra en Audax Proyectos y envíala antes del final del plazo.\n\nGracias.",
        ],
        'manual' => [
            'subject' => 'Recordatorio: weekly pendiente',
            'body' => "Hola {nombre},\n\nTe recordamos que aún no has enviado tu weekly de la semana {semana}.\n\nPor favor, envíala lo antes posible.\n\nGracias.",
        ],
        'weekly_closed' => [
            'subject' => 'Weekly cerrada: {semana}',
            'body' => "Hola {nombre},\n\nLa weekly {semana} ya se ha generado y cerrado.\n\nPuedes revisarla aquí:\n{weekly_url}\n\nGracias.",
        ],
    ],

    'enums' => [
        'cycle_status' => [
            'active' => 'Activa',
            'closed' => 'Cerrada',
        ],
        'cycle_progress' => [
            'finished' => 'Finalizada',
            'upcoming' => 'Próximamente',
            'overdue' => 'Con retraso',
            'completed' => 'Completada',
            'in_progress' => 'Por completar',
        ],
        'person_status' => [
            'upcoming' => 'Próximamente',
            'pending' => 'Pendiente',
            'overdue' => 'Con retraso',
            'submitted' => 'Enviado',
            'submitted_late' => 'Enviado con retraso',
            'missed' => 'No enviada',
            'exempt' => 'Exento',
            'not_required' => 'No requerido',
        ],
        'exemption_reason' => [
            'absence' => 'Ausencia',
            'manual' => 'Exención manual',
            'waived' => 'Sin exención',
        ],
        'entry_source' => [
            'text' => 'Texto',
            'dictation' => 'Dictado',
        ],
        'audio_section' => [
            'intro' => 'Introducción',
            'client' => 'Cliente',
            'outro' => 'Cierre',
        ],
        'job_state' => [
            'queued' => 'En cola',
            'running' => 'Generando',
            'done' => 'Hecho',
            'failed' => 'Error',
        ],
        'client_status' => [
            'on_track' => 'En curso',
            'risk' => 'En riesgo',
            'blocked' => 'Bloqueado',
        ],
        'ai_provider' => [
            'gemini' => 'Google Gemini',
            'google_tts' => 'Google Cloud TTS',
        ],
        'ai_feature' => [
            'weekly_report' => 'Informe semanal',
            'satisfaction' => 'Satisfacción',
            'audio_script' => 'Guion del audio',
            'speech' => 'Locución',
            'transcript_cleanup' => 'Limpieza del dictado',
            'suggested_tasks' => 'Tareas sugeridas',
            'client_summary' => 'Resumen del cliente',
            'team_activity' => 'Actividad del equipo',
            'person_performance' => 'Resumen de desempeño',
            'person_client_activity' => 'Actividad por cliente',
            'assistant' => 'Asistente',
        ],
        'reminder_channel' => [
            'email' => 'Email',
            'push' => 'Navegador',
        ],
        'reminder_template' => [
            'automatic' => 'Automático',
            'manual' => 'Manual',
            'weekly_closed' => 'Weekly cerrada',
        ],
        'reminder_status' => [
            'sent' => 'Enviado',
            'failed' => 'Fallido',
            'skipped' => 'Omitido',
        ],
        'dictation_context' => [
            'weekly_entry' => 'Weekly',
            'task_note' => 'Notas de la tarea',
        ],
        'suggestion_status' => [
            'open' => 'Abierta',
            'future' => 'Futuro',
            'planned' => 'Planificada',
            'building_now' => 'En desarrollo',
            'beta' => 'Beta',
            'completed' => 'Completada',
        ],
        'suggestion_reaction' => [
            'thumbs_up' => 'Me gusta',
            'rocket' => 'Impulso',
            'eyes' => 'Siguiendo',
            'heart' => 'Me encanta',
        ],
        'module' => [
            'weeklies' => 'Weeklies',
            'project_status' => 'Estado de proyectos',
            'help' => 'Centro de ayuda',
            'suggestions' => 'Sugerencias',
            'assistant' => 'Asistente IA',
        ],
    ],
];
