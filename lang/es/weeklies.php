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

    'module_disabled' => 'Este módulo está desactivado.',

    // Modo de prueba (D-239): un admin usa un módulo apagado, sin avisar a nadie.
    'preview' => [
        'no_notices' => 'Modo de prueba: no se avisa a nadie.',
    ],

    // Importación de WeeklySync (10.9b): una imagen del texto original sin dirección web.
    'import_image' => 'Imagen',

    // Aviso de bienvenida al entrar (F-012).
    'welcome' => '¡Hola, :name! Bienvenido de nuevo.',

    // Avisos de las acciones de la entrega 10.2.
    'flash' => [
        'away' => 'Listo: estás fuera. No te llegarán recordatorios de la weekly.',
        'away_with_absence' => 'Listo: estás fuera y has solicitado la ausencia.',
        'away_other' => ':name queda fuera: no le llegarán recordatorios de la weekly.',
        'available' => 'Vuelves a estar disponible.',
        'available_other' => ':name vuelve a estar disponible.',
        'opened' => 'Semana abierta: :label.',
        'deadline_updated' => 'Plazo actualizado.',
        'deadline_updated_notified' => '{1} Plazo actualizado. Se ha avisado a 1 persona pendiente.|[2,*] Plazo actualizado. Se ha avisado a :count personas pendientes.',
        'deleted' => 'Weekly «:label» eliminada.',
        'deleted_and_opened' => 'Weekly «:label» eliminada. Se ha abierto la siguiente: :next.',
        'submitted' => 'Weekly enviada. ¡Gracias!',
        'resubmitted' => 'Weekly actualizada.',
        'exempted' => ':name queda exento de la weekly de esta semana.',
        'exemption_removed' => 'Exención quitada.',
        'waived' => 'Ya puedes rellenar y enviar tu weekly.',
        'waiver_undone' => 'Vuelves a estar exento esta semana.',
        'joined' => '{0} Ya estabas en el equipo de esos clientes.|{1} Te has unido a 1 cliente.|[2,*] Te has unido a :count clientes.',
        'left' => 'Has dejado el cliente :client.',
        'assigned' => '{0} :name ya estaba en el equipo de esos clientes.|{1} :name se ha unido a 1 cliente.|[2,*] :name se ha unido a :count clientes.',
        'unassigned' => ':name ya no está en el equipo de :client.',
        'closed' => 'Weekly «:label» cerrada. Se ha abierto la siguiente: :next. La satisfacción de los clientes se calcula en segundo plano.',
    ],

    // Avisos (10.5, D-199 a D-201): reglas, plantillas, envío manual, «Recordar» y registro.
    'reminders' => [
        'saved' => 'Avisos de la weekly guardados.',
        'sent' => '{0} Nadie necesita el recordatorio: ya han enviado su weekly o están exentos.|{1} Recordatorio enviado a 1 persona.|[2,*] Recordatorio enviado a :count personas.',
        'reminded' => 'Recordatorio enviado a :name.',
        'not_needed' => ':name no necesita el recordatorio: ya ha enviado su weekly, está exento o está fuera hoy.',
        'duplicate' => 'Ya se le ha enviado un recordatorio hace un momento.',
        'no_channel' => 'No se ha enviado: :name tiene desactivados esos avisos.',
        'no_active' => 'No hay ninguna semana activa a la que recordar.',
        'failed' => 'No se ha podido entregar.',
        'skipped' => [
            'channel' => 'Este aviso no sale por ese canal.',
            'push_unavailable' => 'Los avisos del navegador no están configurados.',
            'push_unsubscribed' => 'No tiene ningún navegador con los avisos activados.',
            'preferences' => 'Lo tiene desactivado en sus preferencias.',
            'no_email' => 'No tiene email.',
        ],
        'notice' => [
            'reminder_body' => 'Tu weekly de la :label sigue pendiente. Plazo: :deadline.',
            'reminder_action' => 'Escribir mi weekly',
            'closed_body' => 'Ya puedes leer el informe de la :label.',
            'closed_action' => 'Abrir la weekly',
            'deadline_title' => 'Nuevo plazo para la weekly',
            'deadline_body' => 'El plazo de tu weekly de la :label es ahora el :deadline.',
            'deadline_mail' => "Hola {nombre},\n\nEl plazo de tu weekly de la {week_label} es ahora el :deadline.\n\nGracias.",
        ],
        'away' => [
            'absence_note' => 'Solicitada desde «Estoy fuera» de la Weekly.',
        ],

        'validation' => [
            'away_reason' => 'Elige si estás de vacaciones o ausente.',
            'away_until' => 'La vuelta tiene que ser hoy o un día posterior.',
            'away_absence_needs_until' => 'Para solicitar la ausencia, indica hasta cuándo.',
            'away_absence_failed' => 'No se ha podido solicitar la ausencia.',
            'rules_max' => 'Como mucho :max reglas.',
            'time' => 'La hora tiene que ser HH:MM (de 00:00 a 23:59).',
            'day' => 'El día tiene que ser de lunes a domingo.',
            'channel' => 'El canal tiene que ser «En la app», «Email» o «Navegador».',
            'channels' => 'Elige al menos un canal.',
            'users' => 'Elige al menos una persona.',
        ],
        'attributes' => [
            'rules' => 'reglas',
            'subject' => 'asunto',
            'body' => 'cuerpo',
            'user_ids' => 'personas',
            'channels' => 'canales',
            'template' => 'plantilla',
        ],
    ],

    // Informe, audio y cierre (10.3).
    'report' => [
        'general' => 'General / Interno',
        'queued' => 'Generando el informe. Puede tardar varios minutos: te avisamos aquí cuando esté.',
        'busy' => 'El informe ya se está generando.',
        'closed' => 'La semana está cerrada: su texto ya no se regenera.',
        'saved' => 'Informe actualizado.',
        'empty_edit' => 'Primero genera el informe.',
    ],
    'audio' => [
        'queued' => 'Generando el audio. Puede tardar varios minutos: te avisamos aquí cuando esté.',
        'busy' => 'El audio ya se está generando.',
    ],
    'close' => [
        'blockers' => [
            'not_active' => 'Esta semana ya está cerrada.',
            'report' => 'Falta generar el texto del informe.',
            'audio' => 'Falta generar el audio del informe.',
        ],
    ],

    // PDF, impresión y Excel del informe (F-083, D-192).
    'pdf' => [
        'kind' => 'Weekly',
        'no_report' => 'Aún no se ha generado el informe de esta semana.',
        'legacy_text' => 'Informe importado de WeeklySync: se conserva su texto final.',
        'global_summary' => 'Resumen global',
        'team_risks' => 'Riesgos detectados',
        'next_steps' => 'Siguientes pasos',
        'no_next_steps' => 'No hay pasos definidos.',
        'milestones' => 'Próximos hitos',
        'no_milestones' => 'No hay hitos próximos.',
        'satisfaction' => 'Satisfacción al cerrar: :score / 100',
        'mine_empty' => 'No formas parte de ningún proyecto incluido en esta weekly.',
        'yes' => 'Sí',
        'no' => 'No',
        'facts' => [
            'week' => 'Semana',
            'deadline' => 'Plazo',
            'status' => 'Estado',
            'submissions' => 'Weeklies enviadas',
            'filter' => 'Filtro',
            'only_mine' => 'Solo mis proyectos',
        ],
        'kpis' => [
            'clients' => 'Clientes',
            'with_news' => 'Con novedades',
            'risk' => 'En riesgo',
            'blocked' => 'Bloqueados',
        ],
        'columns' => [
            'client' => 'Cliente',
            'status' => 'Estado',
            'summary' => 'Resumen ejecutivo',
            'next_steps' => 'Siguientes pasos',
            'milestones' => 'Próximos hitos',
            'tags' => 'Etiquetas',
            'satisfaction' => 'Satisfacción',
            'reports' => 'Con reportes',
        ],
        'projects' => [
            'title' => 'Estado de proyectos',
            'code' => 'Código',
            'name' => 'Proyecto',
            'kind' => 'Tipo',
            'consumed' => 'Consumido',
            'budget' => 'Presupuesto',
            'expected' => 'Esperado',
            'week' => 'Esta semana',
        ],
    ],

    'validation' => [
        'deadline_required' => 'Indica el día del plazo.',
        'deadline_range' => 'El plazo tiene que estar entre el lunes de la semana y cuatro semanas después del viernes.',
        'entries' => 'La weekly no tiene un formato válido.',
        'client' => 'Uno de los clientes ya no existe.',
        'body_too_long' => 'Cada apunte puede tener como mucho :max caracteres.',
        'person' => 'Elige a una persona que participe en la weekly de esta semana.',
        'note_too_long' => 'La nota puede tener como mucho 500 caracteres.',
        'not_exempt' => 'No estás exento esta semana.',
        'clients' => 'Elige clientes activos.',
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
        'note' => '- Nota: :note',
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
        'report_failed' => 'No se ha podido generar el informe. Inténtalo de nuevo.',
        'audio_failed' => 'No se ha podido generar el audio. Inténtalo de nuevo.',
        'audio_needs_report' => 'Primero genera el texto del informe.',
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
            'away' => 'Fuera',
        ],
        'away_reason' => [
            'vacation' => 'De vacaciones',
            'absent' => 'Ausente o de baja',
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
            'dictation_transcription' => 'Transcripción del dictado',
            'transcript_cleanup' => 'Limpieza del dictado',
            'suggested_tasks' => 'Tareas sugeridas',
            'client_summary' => 'Resumen del cliente',
            'team_activity' => 'Actividad del equipo',
            'person_performance' => 'Resumen de desempeño',
            'person_client_activity' => 'Actividad por cliente',
            'assistant' => 'Asistente',
        ],
        'reminder_channel' => [
            'app' => 'En la app',
            'email' => 'Email',
            'push' => 'Navegador',
        ],
        'reminder_template' => [
            'automatic' => 'Automático',
            'manual' => 'Manual',
            'weekly_closed' => 'Weekly cerrada',
            'deadline' => 'Plazo cambiado',
            'friday' => 'Recordatorio de los viernes',
        ],
        'reminder_status' => [
            'queued' => 'En cola',
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
    // Tipos de proyecto de WeeklySync (10.4, ProjectKindCode): por prefijo del código y por grupo.
    'project_kinds' => [
        'PR' => 'Producto',
        'EC' => 'Ecommerce',
        'WE' => 'Web',
        'AD' => 'Auditoría diseño',
        'AM' => 'Auditoría marketing',
        'AT' => 'Auditoría técnica',
        'FE' => 'Fee mensual',
        'BH' => 'Bolsa de horas',
        'BR' => 'Branding',
        'GE' => 'General',
    ],
    'project_tags' => [
        'product' => 'Producto',
        'ecommerce' => 'Ecommerce',
        'web' => 'Web',
        'audit' => 'Auditoría',
        'monthly_fee' => 'Fee mensual',
        'hour_bank' => 'Bolsa de horas',
        'branding' => 'Branding',
        'general' => 'General',
    ],
    // Fichas de cliente y de persona con IA (10.4, D-194).
    'insights' => [
        'subject_missing' => 'El cliente o la persona ya no existe.',
        'requested' => 'Generando el resumen. Tardará unos segundos.',
        'already_running' => 'El resumen ya se está generando.',
        'invalid_kind' => 'Ese resumen no existe.',
    ],

    // Tareas de «Mi espacio» (10.6, F-055 a F-063, D-203 y D-204).
    'tasks' => [
        'no_closed_weekly' => 'Todavía no hay ninguna weekly cerrada de la que sacar tareas.',
        'requested' => 'Buscando tareas en la última weekly cerrada. Tardará unos segundos.',
        'already_running' => 'Ya se están buscando tareas en la weekly.',
        'suggestion_gone' => 'Esa propuesta ya no está: vuelve a cargar la página.',
        'project_forbidden' => 'No puedes crear tareas en ese proyecto.',
        'created' => '{1} Se ha creado :count tarea.|[2,*] Se han creado :count tareas.',
        'dismissed' => 'Propuestas descartadas.',
        'archived' => '«:task» archivada en tu lista.',
        'unarchived' => '«:task» recuperada.',
        'errors' => [
            'title' => 'Escribe el título de la tarea.',
            'project' => 'Elige el proyecto de la tarea.',
            'rich_notes' => 'Esta tarea tiene una descripción con formato: edítala en la tarea para no perderlo.',
            'task_forbidden' => 'No puedes cambiar las notas de esta tarea.',
        ],
    ],

    // Asistente IA (10.6, F-146 y F-147, D-205 y D-206).
    // Límites diarios de IA por persona (D-222).
    'ai_limits' => [
        'assistant' => 'Has llegado al límite de :limit preguntas al asistente por hoy. Mañana podrás seguir.',
        'summaries' => 'Has llegado al límite de :limit resúmenes con IA por hoy. Mañana podrás pedir más.',
        'suggested_tasks' => 'Has llegado al límite de :limit propuestas de tareas con IA por hoy. Mañana podrás pedir más.',
    ],
    'assistant' => [
        'busy' => 'Espera a que termine la respuesta anterior antes de preguntar otra cosa.',
        'question_required' => 'Escribe una pregunta.',
        'not_found' => 'Esa pregunta ya no está disponible.',
        'failed' => 'Lo siento, hubo un error al procesar tu consulta. Por favor intenta de nuevo.',
        'empty' => 'No se pudo generar una respuesta.',
        // Las preguntas sugeridas de WeeklySync, con nombres de Audax.
        'suggested' => [
            'client' => '¿Cuál es el estado actual del cliente «:client»?',
            'clients' => '¿Cuál es el estado actual de nuestros clientes?',
            'department' => '¿Qué bloqueos ha reportado el equipo de :department esta semana?',
            'team' => '¿Qué bloqueos ha reportado el equipo esta semana?',
            'person' => 'Hazme un resumen de los logros de :person este mes.',
            'me' => 'Hazme un resumen de mis logros de este mes.',
            'problems' => '¿Cuándo fue la última vez que tuvimos problemas con la API?',
            'risk' => '¿Qué clientes están en riesgo según los últimos reportes?',
        ],
    ],
];
