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
