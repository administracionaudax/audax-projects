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
- comunicarnos con el chat interno.

**Base legal:** la ejecución de tu contrato de trabajo y el interés legítimo de la empresa en organizar el trabajo, dentro de las facultades de control del artículo 20.3 del Estatuto de los Trabajadores.

**Qué datos se tratan:**
- tus datos de identificación y de contacto profesionales,
- las horas que imputas, tus tareas y tus comentarios,
- tus ausencias: solo el tipo y las fechas, nunca diagnósticos ni justificantes médicos,
- tus mensajes del chat y tus audios, con su transcripción, que se hace en el propio servidor de la empresa,
- los registros de acceso a la aplicación: fecha, dirección IP y navegador.

**Quién los ve:**
- Cada persona ve sus propios datos.
- Tus responsables y los gestores de tus proyectos ven lo necesario para organizar el trabajo.
- Los clientes solo ven, en su portal, las horas aprobadas de sus propios proyectos, y tu nombre únicamente si así se configura.
- Nada se cede a terceros ni sale del servidor de la empresa, tampoco la transcripción de los audios.

**Cuánto tiempo se guardan:**
- las horas, durante los plazos legales de conservación de la documentación contable y laboral,
- el resto, según los plazos de retención que la empresa tiene configurados y que puedes consultar aquí.

**Tus derechos:**
- Puedes pedir el acceso, la rectificación, la supresión, la limitación, la oposición y la portabilidad de tus datos.
- Desde «Mis datos» puedes descargar una copia de tus datos personales.
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
            'profile' => [
                'description' => 'Datos de tu cuenta: nombre, email, departamento, rol, preferencias y lectura del texto de privacidad.',
                'columns' => [
                    'id' => 'Id',
                    'name' => 'Nombre',
                    'email' => 'Email',
                    'department' => 'Departamento',
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
                'description' => 'Tus inicios de sesión, correctos y fallidos: fecha, dirección IP y navegador.',
                'columns' => [
                    'created_at' => 'Fecha',
                    'succeeded' => 'Correcto',
                    'ip_address' => 'Dirección IP',
                    'user_agent' => 'Navegador',
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
        // Mensajes del chat: nombre de cada conversación y tipo de mensaje.
        'chat' => [
            'project' => 'Proyecto :project',
            'group' => 'Grupo «:name»',
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
