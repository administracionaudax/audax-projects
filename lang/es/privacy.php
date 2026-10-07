<?php

/*
| Privacidad y RGPD (SPEC §15, D-075). El texto informativo por defecto es un BORRADOR pendiente
| de revisión por el asesor del propietario: el admin lo sustituye desde /admin/privacidad y cada
| cambio pide una nueva lectura a la plantilla.
*/

return [
    'default_notice' => <<<'MD'
> **Borrador pendiente de revisión por el asesor.** Este texto no es definitivo.

## Información sobre el tratamiento de tus datos en Audax Proyectos

**Responsable:** Audax Studio. Para cualquier cuestión sobre tus datos puedes escribir a [correo de contacto de privacidad].

**Para qué se usan tus datos:**
- organizar los proyectos y las tareas de la agencia,
- registrar las horas que dedicas a cada proyecto y facturarlas a los clientes,
- planificar la carga de trabajo del equipo teniendo en cuenta jornadas, festivos y ausencias,
- organizar el día con el plan del día: lo que cada persona prevé hacer y si lo ha hecho,
- llevar el registro diario de tu jornada, que exige el artículo 34.9 del Estatuto de los Trabajadores,
- comunicarnos con el chat interno.

**Base legal:** la ejecución de tu contrato de trabajo y el interés legítimo de la empresa en organizar el trabajo, dentro de las facultades de control del artículo 20.3 del Estatuto de los Trabajadores.

**Registro de jornada:** su base es el cumplimiento de una obligación legal (artículo 6.1.c del RGPD y artículo 34.9 del Estatuto de los Trabajadores), sin necesidad de tu consentimiento. Solo se usa para eso: controlar la jornada, los descansos y las horas extra. No se usa para medir tu productividad ni para ninguna otra finalidad.

**Qué datos se tratan:**
- tus datos de identificación y de contacto profesionales,
- las horas que imputas, tus tareas y tus comentarios,
- tu plan del día: las líneas que escribes, si las haces, las horas que prevés y los comentarios de tu responsable,
- tus ausencias y tus saldos de vacaciones y permisos: el tipo, las fechas, lo que te queda de cada saldo y, si el permiso lo pide, su justificante (nunca un diagnóstico: basta con el justificante de la hospitalización, la citación o el certificado),
- tu registro de jornada: la hora de entrada, de la comida y de salida que fichas, si trabajas presencial o a distancia, las correcciones con su motivo, tus resúmenes mensuales con tu confirmación y tus horas extra y saldo de horas; de la conexión desde la que fichas, solo una huella de la dirección IP que no permite saber cuál era y el navegador. No se usa geolocalización ni datos biométricos,
- tus mensajes del chat y tus audios, con su transcripción, que se hace en el propio servidor de la empresa,
- tus weeklies y sus dictados: el dictado se transcribe con Google Gemini y su audio se borra al transcribirlo,
- los registros de acceso a la aplicación: fecha, dirección IP y navegador.

**Quién los ve:**
- Cada persona ve sus propios datos.
- Tus responsables y los gestores de tus proyectos ven lo necesario para organizar el trabajo.
- Tus justificantes los ves tú y RR. HH.; tu responsable, solo los que no son de salud. El resto de la plantilla solo ve que no estás, nunca el motivo de tu ausencia.
- Tu registro de jornada lo ves tú, tu responsable y RR. HH. La Inspección de Trabajo y, si la hubiera, la representación legal de la plantilla pueden acceder a él en los términos que marca la ley; la Inspección, con un acceso temporal de solo lectura que queda registrado. La comparación entre tu jornada y tus horas imputadas solo la ves tú.
- El texto y el estado de las líneas de tu plan del día los ve el resto de la plantilla; las horas previstas, el cumplimiento y los comentarios, solo tú, tu responsable y la administración. No se hacen clasificaciones entre personas.
- Los clientes solo ven, en su portal, las horas aprobadas de sus propios proyectos, y tu nombre únicamente si así se configura.
- Nada se cede a terceros. Solo las funciones de IA de la Weekly (el informe, su audio, la transcripción y la limpieza de los dictados) usan Google como encargado del tratamiento; los audios del chat se transcriben en el servidor de la empresa.

**Cuánto tiempo se guardan:**
- las horas, durante los plazos legales de conservación de la documentación contable y laboral,
- el registro de jornada, cuatro años contados desde el final de cada mes (artículo 34.9 del Estatuto de los Trabajadores); después se suprime, salvo que haya una reclamación o una inspección abierta, que lo bloquea hasta que termine,
- el resto, según los plazos de retención que la empresa tiene configurados y que puedes consultar aquí.

**Tus derechos:**
- Puedes pedir el acceso, la rectificación, la supresión, la limitación, la oposición y la portabilidad de tus datos.
- Desde «Mis datos» puedes descargar una copia de tus datos personales, y desde «Mi registro», tu registro de jornada de cualquier periodo.
- Si no estás de acuerdo con cómo se tratan, puedes reclamar ante la Agencia Española de Protección de Datos (www.aepd.es).
MD,

    'exports' => [
        'status' => [
            'pending' => 'En cola',
            'processing' => 'Preparándose',
            'ready' => 'Lista para descargar',
            'failed' => 'Ha fallado',
            'expired' => 'Caducada',
        ],
        'requested_own' => 'Estamos preparando tus datos. En unos minutos podrás descargarlos desde esta página.',
        'requested_for' => 'Estamos preparando los datos de :name. Podrás descargarlos desde su ficha.',
        'filename' => 'datos personales :name',
        'errors' => [
            'in_progress' => 'Ya hay una exportación en curso. Espera a que termine para pedir otra.',
        ],
    ],

    // /privacidad: lectura del texto informativo.
    'notice' => [
        'acknowledged' => 'Gracias: queda registrado que has leído la versión :version.',
    ],

    // Plazos de conservación (RetentionPolicy): nombres de cada tipo de dato.
    'retention' => [
        'types' => [
            'login_events' => 'Registros de acceso',
            'read_notifications' => 'Notificaciones leídas',
            'activity_log' => 'Registro de cambios (auditoría)',
            'chat_messages' => 'Mensajes del chat',
            'weekly_reminder_logs' => 'Registro de avisos de la Weekly',
            'dictations' => 'Dictados de la Weekly',
            'ai_usage' => 'Uso de la IA (quién y sobre qué)',
            'day_plans' => 'Plan del día',
            'people_register' => 'Registro de jornada (mínimo 48 meses)',
        ],
    ],

    // /admin/privacidad.
    'admin' => [
        'saved' => 'Ajustes de privacidad guardados.',
        'saved_new_version' => 'Guardado. El texto informativo pasa a la versión :version: toda la plantilla tendrá que volver a leerlo.',
        'errors' => [
            'notice_required' => 'Escribe el texto informativo.',
            'notice_max' => 'El texto puede tener como mucho :max caracteres.',
            'months_range' => 'Indica un número de meses entre :min y :max.',
        ],
        'attributes' => [
            'notice' => 'texto informativo',
            'personal_data_export_days' => 'días para descargar los datos personales',
            'disk_warning_percent' => 'aviso de disco',
            'attachments_warning_gb' => 'aviso de adjuntos',
        ],
    ],

    // ZIP de datos personales (App\Domain\Privacy\Export): LEEME.txt y cada sección.
    'export' => [
        'readme' => [
            'title' => 'Tus datos personales en Audax Proyectos',
            'person' => 'Persona: :name (:email)',
            'generated' => 'Generado el :date (hora de Madrid).',
            'intro' => 'Este archivo contiene una copia de los datos personales que la aplicación guarda sobre ti (derecho de acceso y portabilidad, artículos 15 y 20 del RGPD).',
            'files' => 'Ficheros (cada sección va en JSON y en CSV, con el mismo contenido):',
            'file' => '- :name.json y :name.csv: :description Filas: :rows.',
            'formats' => 'Formatos: JSON en UTF-8; CSV separado por punto y coma (;), en UTF-8 con BOM, que se abre directamente en Excel. Las fechas van como AAAA-MM-DD, los instantes en ISO 8601 con la hora de Madrid y las duraciones en minutos.',
            'excluded' => 'No se incluyen la contraseña ni los códigos del doble factor (se guardan cifrados y nadie puede leerlos), ni los costes y tarifas de la empresa.',
            'availability' => 'El enlace de descarga caduca a los :days días; después el fichero se borra del servidor.',
            'contact' => 'Si echas en falta algún dato o quieres ejercer otro derecho (rectificación, supresión, limitación u oposición), escribe a quien figura como responsable en la página Privacidad de la aplicación.',
        ],
        'sections' => [
            'clock_events' => [
                'description' => 'Tu registro de jornada: cada fichaje (entrada, comida, vuelta y salida) y cada anulación de una corrección aceptada, con la hora en que pasó y en que se registró, el modo, quién lo escribió y su huella. La IP nunca se guarda.',
                'columns' => [
                    'seq' => 'Nº de fila', 'kind' => 'Tipo', 'occurred_at' => 'Cuándo pasó', 'recorded_at' => 'Cuándo se registró', 'work_mode' => 'Modo',
                    'pause_type' => 'Pausa', 'source' => 'Origen', 'voids_seq' => 'Anula la fila nº', 'correction_id' => 'Corrección', 'author' => 'Escrito por',
                    'user_agent' => 'Navegador', 'prev_hash' => 'Huella de la fila anterior', 'hash' => 'Huella',
                ],
            ],
            'clock_corrections' => [
                'description' => 'Las correcciones de tu registro: quién las propuso, el motivo, lo que anulan y añaden y la decisión o la discrepancia.',
                'columns' => [
                    'id' => 'Id', 'date' => 'Día', 'proposed_by' => 'Propuesta por', 'proposed_at' => 'Propuesta el', 'reason' => 'Motivo', 'voids' => 'Anula (ids)',
                    'adds' => 'Añade', 'status' => 'Estado', 'decided_by' => 'Decidida por', 'decided_at' => 'Decidida el', 'decision_note' => 'Nota', 'dispute_reason' => 'Discrepancia',
                ],
            ],
            'month_closes' => [
                'description' => 'Tus resúmenes mensuales del registro y tu respuesta (confirmación o desacuerdo), con la huella de cada PDF.',
                'columns' => [
                    'month' => 'Mes', 'version' => 'Versión', 'status' => 'Estado', 'worked_minutes' => 'Trabajado (min)', 'expected_minutes' => 'Teórico (min)',
                    'difference_minutes' => 'Diferencia (min)', 'overtime_minutes' => 'Horas extra (min)', 'generated_at' => 'Generado el', 'confirmed_at' => 'Confirmado el',
                    'disagreed_at' => 'Desacuerdo el', 'disagreement_note' => 'Motivo del desacuerdo', 'reopened_at' => 'Desconfirmado el', 'reopen_reason' => 'Motivo de la desconfirmación',
                    'pdf_sha256' => 'Huella del PDF',
                ],
            ],
            'overtime' => [
                'description' => 'La clasificación del exceso de tus días: horas extra o complementarias, flexibilidad y su destino.',
                'columns' => [
                    'date' => 'Día', 'hour_type' => 'Tipo de hora', 'excess_minutes' => 'Exceso (min)', 'overtime_minutes' => 'Horas extra (min)', 'flex_minutes' => 'Flexibilidad (min)',
                    'destination' => 'Destino', 'decided_by' => 'Decidido por', 'decided_at' => 'Decidido el', 'supersedes_id' => 'Sustituye a', 'note' => 'Nota',
                ],
            ],
            'leave_balance' => [
                'description' => 'Los movimientos de tus saldos de vacaciones y permisos: asignaciones anuales, ajustes, saldo inicial y arrastres, con su caducidad. Las cantidades en días van en centésimas (2200 = 22 días); las de horas, en minutos.',
                'columns' => ['type' => 'Tipo', 'year' => 'Año', 'kind' => 'Movimiento', 'amount' => 'Cantidad', 'unit' => 'Unidad', 'valid_from' => 'Desde', 'expires_on' => 'Caduca', 'reason' => 'Motivo', 'created_by' => 'Anotado por', 'created_at' => 'Anotado el'],
            ],
            'absence_documents' => [
                'description' => 'Los justificantes que se han subido a tus ausencias (el fichero se descarga desde «Mis ausencias»).',
                'columns' => ['absence_id' => 'Id de la ausencia', 'name' => 'Fichero', 'mime' => 'Formato', 'size' => 'Tamaño (bytes)', 'sha256' => 'Huella SHA-256', 'uploaded_by' => 'Subido por', 'created_at' => 'Subido el'],
            ],
            'time_balance' => [
                'description' => 'Los movimientos de tu saldo de horas: horas extra a compensar, descansos disfrutados, pagos y ajustes.',
                'columns' => ['date' => 'Fecha', 'kind' => 'Tipo', 'minutes' => 'Minutos', 'reason' => 'Motivo', 'created_by' => 'Anotado por', 'created_at' => 'Anotado el'],
            ],
            'employment' => [
                'description' => 'Tus datos laborales del registro (alta, baja, si estás sujeto al registro, tiempo parcial y retención por litigio) y los documentos de RR. HH. que has leído.',
                'columns' => ['field' => 'Dato', 'value' => 'Valor', 'date' => 'Fecha'],
            ],
            'profile' => [
                'description' => 'Datos de tu cuenta: nombre, email, departamento, puesto, rol, preferencias, lectura del texto de privacidad y tu «Estoy fuera» de la Weekly.',
                'columns' => [
                    'id' => 'Id',
                    'name' => 'Nombre',
                    'email' => 'Email',
                    'department' => 'Departamento',
                    'job_title' => 'Puesto',
                    'roles' => 'Rol',
                    'is_active' => 'Cuenta activa',
                    'two_factor_enabled' => 'Doble factor activado',
                    'theme_preference' => 'Tema',
                    'locale' => 'Idioma',
                    'notification_preferences' => 'Preferencias de notificación',
                    'privacy_acknowledged_version' => 'Versión del texto de privacidad leída',
                    'privacy_acknowledged_at' => 'Leída el',
                    'email_verified_at' => 'Email verificado el',
                    'created_at' => 'Alta',
                    'weekly_away_reason' => 'Fuera en la Weekly',
                    'weekly_away_since' => 'Fuera desde',
                    'weekly_away_until' => 'Fuera hasta',
                    'has_avatar' => 'Foto de perfil (en foto-perfil.webp)',
                ],
            ],
            'schedules' => [
                'description' => 'Tus jornadas: cada versión con los minutos de cada día.',
                'columns' => [
                    'valid_from' => 'Desde',
                    'valid_to' => 'Hasta',
                    'mon_minutes' => 'Lunes (minutos)',
                    'tue_minutes' => 'Martes (minutos)',
                    'wed_minutes' => 'Miércoles (minutos)',
                    'thu_minutes' => 'Jueves (minutos)',
                    'fri_minutes' => 'Viernes (minutos)',
                    'sat_minutes' => 'Sábado (minutos)',
                    'sun_minutes' => 'Domingo (minutos)',
                    'weekly_total' => 'Total semanal',
                ],
            ],
            'time_entries' => [
                'description' => 'Tus horas imputadas: fecha, proyecto, tarea, duración, estado y descripción.',
                'columns' => [
                    'id' => 'Id',
                    'date' => 'Fecha',
                    'client' => 'Cliente',
                    'project' => 'Proyecto',
                    'hour_bank' => 'Bolsa',
                    'task' => 'Tarea',
                    'minutes' => 'Minutos',
                    'duration' => 'Duración',
                    'is_billable' => 'Facturable',
                    'status' => 'Estado',
                    'description' => 'Descripción',
                    'started_at' => 'Inicio del temporizador',
                    'ended_at' => 'Fin del temporizador',
                    'approved_at' => 'Aprobada el',
                    'created_at' => 'Creada el',
                    'updated_at' => 'Modificada el',
                ],
            ],
            'absences' => [
                'description' => 'Tus ausencias: tipo, fechas, estado y notas.',
                'columns' => [
                    'id' => 'Id',
                    'type' => 'Tipo',
                    'start_date' => 'Desde',
                    'end_date' => 'Hasta',
                    'partial_minutes' => 'Minutos (parte del día)',
                    'status' => 'Estado',
                    'notes' => 'Notas',
                    'review_comment' => 'Respuesta',
                    'reviewed_at' => 'Revisada el',
                    'created_at' => 'Pedida el',
                ],
            ],
            'comments' => [
                'description' => 'Tus comentarios en las tareas, en texto plano (también los borrados que siguen guardados).',
                'columns' => [
                    'id' => 'Id',
                    'task_id' => 'Id de la tarea',
                    'task' => 'Tarea',
                    'project' => 'Proyecto',
                    'body' => 'Comentario',
                    'created_at' => 'Escrito el',
                    'edited_at' => 'Editado el',
                    'deleted_at' => 'Borrado el',
                ],
            ],
            'notifications' => [
                'description' => 'Tus notificaciones de la campana.',
                'columns' => [
                    'created_at' => 'Fecha',
                    'read_at' => 'Leída el',
                    'kind' => 'Tipo',
                    'title' => 'Título',
                    'body' => 'Detalle',
                    'url' => 'Enlace',
                ],
            ],
            'integrations' => [
                'description' => 'Las cuentas externas que tienes conectadas (Google, para exportar a Google Sheets): el servicio, la cuenta y desde cuándo. Nunca los tokens de acceso.',
                'columns' => [
                    'service' => 'Servicio',
                    'account' => 'Cuenta',
                    'connected_at' => 'Conectada el',
                ],
            ],
            'login_events' => [
                'description' => 'Tus inicios de sesión, correctos y fallidos: fecha, método (contraseña o Google), dirección IP y navegador.',
                'columns' => [
                    'created_at' => 'Fecha',
                    'succeeded' => 'Correcto',
                    'method' => 'Método (password: contraseña; google: Google)',
                    'ip_address' => 'Dirección IP',
                    'user_agent' => 'Navegador',
                ],
            ],
            'weekly_submissions' => [
                'description' => 'Tus weeklies: una fila por semana con el primer envío, el último reenvío, el último autoguardado del borrador y cuántos apuntes tiene.',
                'columns' => [
                    'id' => 'Id',
                    'week' => 'Semana',
                    'week_label' => 'Etiqueta',
                    'deadline_date' => 'Plazo',
                    'submitted_at' => 'Enviada el',
                    'resubmitted_at' => 'Reenviada el',
                    'draft_saved_at' => 'Borrador guardado el',
                    'entries' => 'Apuntes',
                    'created_at' => 'Creada el',
                ],
            ],
            'weekly_entries' => [
                'description' => 'Los apuntes de tus weeklies: semana, cliente, proyecto, texto y si lo dictaste.',
                'columns' => [
                    'id' => 'Id',
                    'week' => 'Semana',
                    'client' => 'Cliente',
                    'project' => 'Proyecto',
                    'body' => 'Texto',
                    'source' => 'Origen',
                    'created_at' => 'Escrito el',
                    'updated_at' => 'Modificado el',
                ],
            ],
            'day_plans' => [
                'description' => 'Tu plan del día: cada línea con su día, texto, cliente, proyecto, tarea, horas previstas, estado y si se pasó de otro día, y la nota de cada día. Tus horas imputadas van en su propia sección.',
                'columns' => [
                    'id' => 'Id',
                    'date' => 'Día',
                    'position' => 'Orden',
                    'text' => 'Línea',
                    'client' => 'Cliente',
                    'project' => 'Proyecto',
                    'task' => 'Tarea',
                    'planned_minutes' => 'Horas previstas (min)',
                    'status' => 'Estado',
                    'not_done_reason' => 'Motivo de «no hecha»',
                    'carry_count' => 'Veces pasada de un día a otro',
                    'note' => 'Nota del día',
                    'deleted' => 'Borrada',
                    'created_at' => 'Escrita el',
                    'status_changed_at' => 'Estado cambiado el',
                ],
            ],
            'day_plan_comments' => [
                'description' => 'Los comentarios del plan del día: los que te han dejado en tus líneas y los que has escrito tú.',
                'columns' => [
                    'id' => 'Id',
                    'date' => 'Día',
                    'line' => 'Línea',
                    'line_owner' => 'Plan de',
                    'author' => 'Autor',
                    'body' => 'Comentario',
                    'created_at' => 'Escrito el',
                ],
            ],
            'dictations' => [
                'description' => 'Tus dictados: la transcripción literal, el texto limpio y el aviso si no se oyó nada. El audio no se guarda: se borra al transcribirlo.',
                'columns' => [
                    'id' => 'Id',
                    'context' => 'Para',
                    'week' => 'Semana',
                    'client' => 'Cliente',
                    'status' => 'Estado',
                    'raw_text' => 'Transcripción literal',
                    'text' => 'Texto',
                    'warning' => 'Aviso',
                    'audio_duration_ms' => 'Duración del audio (ms)',
                    'created_at' => 'Dictado el',
                    'transcribed_at' => 'Transcrito el',
                ],
            ],
            'weekly_exemptions' => [
                'description' => 'Tus exenciones de la weekly: semana, motivo y nota.',
                'columns' => [
                    'id' => 'Id',
                    'week' => 'Semana',
                    'reason' => 'Motivo',
                    'absence_id' => 'Ausencia',
                    'note' => 'Nota',
                    'by_myself' => 'La pusiste tú',
                    'created_at' => 'Fecha',
                ],
            ],
            'ai_summaries' => [
                'description' => 'Los resúmenes hechos con IA sobre ti: tu desempeño, tu actividad por cliente y lo que dice de ti el análisis del equipo de cada cliente.',
                'columns' => [
                    'id' => 'Id',
                    'kind' => 'Tipo',
                    'about' => 'Cliente',
                    'content' => 'Texto',
                    'model' => 'Modelo de IA',
                    'generated_at' => 'Generado el',
                ],
            ],
            'ai_usage' => [
                'description' => 'Las llamadas a la IA que pediste y las que trataron sobre ti: fecha, función, modelo y estado. Nunca se guardan los textos.',
                'columns' => [
                    'id' => 'Id',
                    'relation' => 'Relación',
                    'feature' => 'Función',
                    'operation' => 'Operación',
                    'provider' => 'Proveedor',
                    'model' => 'Modelo de IA',
                    'status' => 'Estado',
                    'subject' => 'Sobre',
                    'created_at' => 'Fecha',
                ],
            ],
            'weekly_reminders' => [
                'description' => 'Los avisos de la weekly que te han enviado: tipo, canal, estado y el nombre y el email usados.',
                'columns' => [
                    'id' => 'Id',
                    'week' => 'Semana',
                    'template' => 'Aviso',
                    'channel' => 'Canal',
                    'status' => 'Estado',
                    'error' => 'Error',
                    'recipient_name' => 'Nombre',
                    'recipient_email' => 'Email',
                    'created_at' => 'Fecha',
                ],
            ],
            'suggestions' => [
                'description' => 'Las sugerencias que has publicado en el centro de ayuda, con su estado y cuántos votos y comentarios tienen.',
                'columns' => [
                    'id' => 'Id',
                    'board' => 'Tablero',
                    'category' => 'Categoría',
                    'title' => 'Título',
                    'body' => 'Detalle',
                    'status' => 'Estado',
                    'votes' => 'Votos',
                    'comments' => 'Comentarios',
                    'attachments' => 'Adjuntos',
                    'created_at' => 'Publicada el',
                    'updated_at' => 'Cambiada el',
                ],
            ],
            'suggestion_comments' => [
                'description' => 'Tus comentarios en las sugerencias, con sus adjuntos.',
                'columns' => [
                    'id' => 'Id',
                    'post' => 'Sugerencia',
                    'reply_to' => 'Responde al comentario',
                    'body' => 'Texto',
                    'attachments' => 'Adjuntos',
                    'created_at' => 'Escrito el',
                    'edited_at' => 'Editado el',
                ],
            ],
            'suggestion_votes' => [
                'description' => 'Tus votos a las sugerencias y tus reacciones a sus comentarios.',
                'columns' => [
                    'kind' => 'Tipo',
                    'post' => 'Sugerencia',
                    'reaction' => 'Reacción',
                    'date' => 'Fecha',
                ],
            ],
            'help_likes' => [
                'description' => 'Los «me gusta» que has dado a las novedades del centro de ayuda.',
                'columns' => [
                    'update' => 'Novedad',
                    'date' => 'Fecha',
                ],
            ],
            'my_space_tasks' => [
                'description' => 'Lo tuyo de las tareas de «Mi espacio»: las tareas que te ha propuesto la IA y aún no has creado ni descartado, y las que has archivado de tu lista.',
                'columns' => [
                    'kind' => 'Tipo',
                    'title' => 'Tarea',
                    'client' => 'Cliente',
                    'week' => 'Semana de origen',
                    'author' => 'Del reporte de',
                    'date' => 'Fecha',
                ],
            ],
            'chat_messages' => [
                'description' => 'Tus mensajes del chat (no los de otras personas): conversación, fecha, texto, adjuntos y la transcripción de tus audios. También los borrados que siguen guardados.',
                'columns' => [
                    'id' => 'Id',
                    'conversation' => 'Conversación',
                    'type' => 'Tipo',
                    'body' => 'Texto',
                    'attachments' => 'Adjuntos',
                    'transcription' => 'Transcripción',
                    'reply_to' => 'Responde al mensaje',
                    'created_at' => 'Escrito el',
                    'edited_at' => 'Editado el',
                    'deleted_at' => 'Borrado el',
                    'hidden_at' => 'Ocultado por la administración el',
                ],
            ],
        ],
        // Centro de ayuda y sugerencias (10.7).
        'help' => [
            'vote' => 'Voto a una sugerencia',
            'reaction' => 'Reacción a un comentario',
        ],
        // La Weekly (Fase 10, 10.5): estados del dictado y tipos de resumen con IA.
        'weeklies' => [
            // Mi espacio (10.6).
            'my_space_kind' => [
                'suggestion' => 'Tarea sugerida por la IA (sin crear)',
                'archived' => 'Tarea archivada de mi lista',
            ],
            'dictation_status' => [
                'pending' => 'En cola',
                'processing' => 'Transcribiendo',
                'done' => 'Hecho',
                'failed' => 'Fallido',
            ],
            'ai_usage_relation' => [
                'mine' => 'La pediste tú',
                'about' => 'Trata sobre ti',
                'both' => 'La pediste tú y trata sobre ti',
            ],
            'ai_kinds' => [
                'client_summary' => 'Resumen del cliente',
                'client_team_activity' => 'Actividad del equipo en un cliente',
                'person_performance' => 'Tu desempeño',
                'person_client_activity' => 'Tu actividad por cliente',
            ],
        ],
        // Mensajes del chat: nombre de cada conversación y tipo de mensaje.
        'chat' => [
            'project' => 'Proyecto :project',
            'group' => 'Grupo «:name»',
            'team' => 'Canal «:name»',
            'client' => 'Canal del cliente :client',
            'direct' => 'Directa con :person',
            'someone' => 'alguien',
            'types' => [
                'text' => 'Texto',
                'audio' => 'Audio',
                'file' => 'Archivo',
            ],
        ],
    ],
];
