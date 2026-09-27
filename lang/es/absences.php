<?php

/*
| Mensajes del área de festivos y ausencias (SPEC §4.1, §9 y §13; D-049 y D-050).
| Se usan con __('absences.…').
*/

return [
    'errors' => [
        'user_inactive' => 'La persona está desactivada: no se le pueden registrar ausencias.',
        'user_not_internal' => 'Solo las personas de la agencia tienen ausencias.',
        'end_before_start' => 'La fecha de fin no puede ser anterior a la de inicio.',
        'too_long' => 'Una ausencia puede durar como mucho un año. Divídela en varias.',
        'too_far' => 'Las fechas tienen que estar entre el :from y el :to.',
        'partial_single_day' => 'Una ausencia de parte del día solo puede ser de un día.',
        'partial_range' => 'Indica cuántas horas faltas: entre 0:01 y 23:59. Para el día entero, elige «Día completo».',
        'overlap' => 'Se solapa con otra ausencia tuya: :type :period (:status).',
        'overlap_other' => 'Se solapa con otra ausencia de :name: :type :period (:status).',
        'not_requested' => 'Esta ausencia ya no está pendiente: está :status.',
        'not_cancellable' => 'Esta ausencia ya no se puede cancelar.',
        'not_editable' => 'Esta ausencia ya no se puede modificar: está :status.',
        'reject_comment_required' => 'Explica por qué no se aprueba: el comentario es obligatorio.',
        'cannot_register' => 'No puedes registrar ausencias de esta persona.',
    ],

    'flash' => [
        'requested' => 'Solicitud enviada: :type :period. Te avisaremos cuando la revisen.',
        'auto_approved' => 'Ausencia registrada y aprobada: :type :period.',
        'registered' => 'Ausencia de :name registrada y aprobada: :type :period.',
        'approved' => 'Ausencia de :name aprobada.',
        'rejected' => 'Ausencia de :name rechazada. Se lo hemos comunicado.',
        'cancelled' => 'Ausencia cancelada.',
        'annulled' => 'Ausencia de :name anulada.',
        'updated' => 'Ausencia de :name modificada: :type :period. Se lo hemos comunicado.',
        'unchanged' => 'La ausencia de :name no tenía cambios.',
    ],

    // Fechas de una ausencia en frases («del 05/10/2026 al 09/10/2026», «el 05/10/2026»).
    'period' => [
        'range' => 'del :from al :to',
        'day' => 'el :date',
        'partial' => 'el :date (:minutes)',
    ],

    // El tipo dentro de una frase («ha solicitado vacaciones», «ha solicitado un permiso»).
    'type_phrases' => [
        'vacation' => 'vacaciones',
        'sick' => 'una baja',
        'leave' => 'un permiso',
        'training' => 'formación externa',
        'other' => 'una ausencia',
    ],

    'notifications' => [
        'requested' => [
            'title' => ':name ha solicitado :phrase :period',
            'hint' => 'Apruébala o recházala desde «Ausencias del equipo».',
        ],
        'approved' => [
            'title' => 'Ausencia aprobada: :type :period',
            'body' => 'La ha aprobado :reviewer.',
        ],
        'registered' => [
            'title' => ':actor ha registrado tu ausencia: :type :period',
            'body' => 'Ya está aprobada y resta de tu capacidad.',
        ],
        'rejected' => [
            'title' => 'Ausencia no aprobada: :type :period',
            'body' => ':reviewer: «:comment»',
        ],
        'annulled' => [
            'title' => ':actor ha anulado tu ausencia: :type :period',
            'body' => 'Esos días vuelven a contar como jornada normal.',
        ],
        'withdrawn' => [
            'title' => ':name ha cancelado su ausencia: :type :period',
            'body' => 'Esos días vuelven a contar como jornada normal.',
        ],
        'updated' => [
            'title' => ':actor ha modificado tu ausencia: :type :period',
            'body' => 'Antes: :before.',
        ],
    ],

    'mail' => [
        'greeting' => 'Hola, :name:',
        'action_team' => 'Ver las ausencias del equipo',
        'action_mine' => 'Ver mis ausencias',
        'salutation' => "Un saludo,\n:company",
    ],

    // Aviso al imputar en un día con ausencia aprobada (SPEC §7).
    'warnings' => [
        'time_entry' => 'Ese día hay una ausencia aprobada (:type). Revisa que la fecha sea correcta.',
        'time_entry_partial' => 'Ese día hay una ausencia aprobada de parte del día (:type, :minutes).',
    ],

    'holidays' => [
        'created' => 'Festivo añadido: :name (:date).',
        'updated' => 'Festivo actualizado: :name (:date).',
        'deleted' => 'Festivo eliminado: :name (:date).',
        'national_added' => '{0} Los festivos nacionales de :year ya estaban todos.|{1} Se ha añadido 1 festivo nacional de :year.|[2,*] Se han añadido :count festivos nacionales de :year.',
        'imported' => '{0} No se ha añadido ningún festivo.|{1} Se ha añadido 1 festivo.|[2,*] Se han añadido :count festivos.',
        'skipped' => '{1} 1 fecha ya tenía festivo y se ha dejado como estaba.|[2,*] :count fechas ya tenían festivo y se han dejado como estaban.',
        'errors' => [
            'date_taken' => 'Ese día ya tiene un festivo.',
            'year_range' => 'Elige un año entre :min y :max.',
        ],
        'import' => [
            'empty' => 'El fichero está vacío.',
            'too_many' => 'El fichero tiene más de :max líneas. Divídelo en varios.',
            'no_events' => 'El calendario no tiene ningún evento (VEVENT).',
            'unknown_format' => 'No se reconoce el fichero: usa un calendario .ics o un CSV con líneas «AAAA-MM-DD;Nombre».',
            'missing_date' => 'Falta la fecha.',
            'invalid_date' => 'La fecha «:value» no es válida: usa AAAA-MM-DD o DD/MM/AAAA.',
            'missing_name' => 'Falta el nombre del festivo.',
            'missing_dtstart' => 'El evento no tiene fecha de inicio (DTSTART).',
            'missing_summary' => 'El evento no tiene nombre (SUMMARY).',
            'out_of_range' => 'La fecha :date está fuera del rango admitido (:min a :max).',
            'existing' => 'Ya hay un festivo ese día: «:name». Se deja como está.',
            'duplicate' => 'La fecha se repite en el fichero (línea :line).',
            'invalid_end' => 'La fecha de fin «:value» no es válida.',
            'invalid_duration' => 'La duración «:value» no es válida.',
            'too_long' => 'El evento dura :days días: un festivo importado dura :max días como mucho.',
            'multi_day' => 'Evento de :days días (del :from al :to): se añade un festivo por día.',
            'recurring' => 'Se repite cada año desde el :from: se toma su fecha de :year.',
            'recurring_not_in_year' => 'Se repite cada año desde el :from, pero no cae en :year.',
            'recurring_unsupported' => 'Se repite de una forma que no se puede importar (:rule). Añádelo a mano.',
        ],
        'names' => [
            'new_year' => 'Año Nuevo',
            'epiphany' => 'Epifanía del Señor',
            'good_friday' => 'Viernes Santo',
            'labour_day' => 'Fiesta del Trabajo',
            'assumption' => 'Asunción de la Virgen',
            'national_day' => 'Fiesta Nacional de España',
            'all_saints' => 'Todos los Santos',
            'constitution_day' => 'Día de la Constitución Española',
            'immaculate_conception' => 'Inmaculada Concepción',
            'christmas' => 'Natividad del Señor',
        ],
    ],

    // Auditoría (activity log).
    'activity' => [
        'holiday_created' => 'Festivo añadido',
        'holiday_updated' => 'Festivo editado',
        'holiday_deleted' => 'Festivo eliminado',
        'holidays_national' => 'Festivos nacionales añadidos',
        'holidays_imported' => 'Festivos importados',
    ],

    // Nombres de los campos en los errores de validación.
    'attributes' => [
        'type' => 'tipo',
        'start_date' => 'fecha de inicio',
        'end_date' => 'fecha de fin',
        'partial_minutes' => 'horas',
        'notes' => 'notas',
        'comment' => 'comentario',
        'user_id' => 'persona',
        'date' => 'fecha',
        'name' => 'nombre',
        'year' => 'año',
        'file' => 'fichero',
        'rows' => 'festivos',
    ],
];
