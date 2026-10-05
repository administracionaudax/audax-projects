<?php

/*
| Mensajes del dominio de horas (SPEC §7 y §8). Se usan con __('time.…').
*/

return [
    'errors' => [
        'user_inactive' => 'La persona está desactivada: no se le pueden imputar horas.',
        'user_not_internal' => 'Solo se imputan horas a personas de la agencia.',
        'cannot_log_for' => 'No puedes imputar horas en nombre de esta persona en este proyecto.',
        'owner_change' => 'No se puede cambiar la persona de una entrada. Bórrala y crea otra.',
        'task_deleted' => 'La tarea se ha eliminado.',
        'project_archived' => 'El proyecto «:project» está archivado: no admite horas.',
        'milestone' => 'Los hitos no llevan horas.',
        'task_without_bank' => 'La tarea no tiene bolsa. Asígnale una antes de imputar.',
        'bank_closed' => 'La bolsa «:bank» está :status: no admite horas.',
        'bank_frozen' => 'La bolsa «:bank» está :status: sus horas ya no se pueden cambiar ni borrar, solo su descripción. Si hay que corregirlas, pídeselo a un administrador.',
        'bank_department' => 'La bolsa «:bank» es solo para el departamento :department.',
        'bank_blocked' => 'La bolsa «:bank» no admite exceso. Saldo disponible: :available.',
        // Para un colaborador externo, sin el saldo (D-134).
        'bank_blocked_collaborator' => 'La bolsa «:bank» no admite más horas.',
        'not_member' => 'Solo los miembros del proyecto pueden imputar horas.',
        'collaborator_internal' => 'Los colaboradores externos no imputan horas en los proyectos internos.',
        'future_date' => 'No se pueden imputar horas en fechas futuras.',
        'week_closed' => 'La semana del :week está :status. Hay que reabrirla para cambiar sus horas.',
        'minutes_range' => 'La duración debe estar entre 0:01 y 24:00.',
        'day_over_24h' => 'Con esta entrada, el :date suma más de 24 horas.',
        // Franja horaria de una entrada manual (D-172).
        'range_format' => 'Escribe la hora como 09:30.',
        'range_incomplete' => 'Indica la hora de inicio y la de fin, o ninguna de las dos.',
        'range_empty' => 'La hora de fin tiene que ser posterior a la de inicio.',
        'range_midnight' => 'La hora de fin tiene que ser posterior a la de inicio. Si trabajaste pasada la medianoche, regístralo en dos entradas: una hasta las 24:00 (fin 00:00) y otra desde las 00:00 del día siguiente.',
        'range_order' => 'La franja horaria no es válida: el fin es anterior al inicio.',
        'description_required' => 'Escribe una descripción de lo que has hecho.',
        'timer_bank_empty' => 'La bolsa «:bank» no tiene saldo y no admite exceso: no se puede iniciar el temporizador.',
        'no_timer' => 'No tienes ningún temporizador en marcha.',
        'running_timer_failed' => 'No se ha podido imputar el temporizador que tienes en marcha en «:task». Páralo antes de iniciar otro.',
        // Flujo de la semana (D-020, D-034)
        'week_future' => 'Esta semana aún no ha empezado: no se puede enviar.',
        'week_not_submitted' => 'Solo se pueden aprobar o devolver semanas enviadas. La del :week está :status.',
        'week_not_reopenable' => 'Solo se pueden reabrir semanas aprobadas o bloqueadas.',
        'week_invalid' => 'La semana no es válida. Usa el formato 2026-W39.',
        'return_comment_required' => 'Explica qué hay que corregir: el comentario es obligatorio.',
        // Bloqueo (D-034)
        'lock_scope_required' => 'Elige un cliente o un proyecto.',
        'lock_scope_single' => 'Elige un cliente o un proyecto, no los dos.',
        'lock_nothing' => 'No hay horas aprobadas que bloquear en ese rango.',
        'lock_already_unlocked' => 'Este bloqueo ya se deshizo el :date.',
        // Personas y búsqueda
        'cannot_view_hours' => 'No puedes ver las horas de esta persona.',
    ],
    'warnings' => [
        'task_completed' => 'La tarea ya está completada.',
        'over_capacity' => 'Ese día sumas :total, más de un 25 % por encima de tu jornada (:capacity).',
        'no_capacity' => 'Ese día no tienes jornada y sumas :total.',
        // Al imputar por otra persona (D-036), los avisos la nombran a ella.
        'over_capacity_other' => 'Ese día :name suma :total, más de un 25 % por encima de su jornada (:capacity).',
        'no_capacity_other' => 'Ese día :name no tiene jornada y suma :total.',
        'overage_all' => 'Esta bolsa está agotada: estas horas se registrarán como exceso.',
        'overage_partial' => ':minutes de esta entrada se registrarán como exceso: la bolsa se agota.',
        // Para un colaborador externo, sin cantidades (D-134).
        'overage_partial_collaborator' => 'Parte de esta entrada se registrará como exceso: la bolsa se agota.',
        // Franjas que se pisan con otras entradas de la misma persona (D-172): avisa, no bloquea.
        'overlap' => '{1} Esta franja se solapa con otra entrada tuya: :ranges.|[2,*] Esta franja se solapa con :count entradas tuyas: :ranges.',
        'overlap_other' => '{1} Esta franja se solapa con otra entrada de :name: :ranges.|[2,*] Esta franja se solapa con :count entradas de :name: :ranges.',
        'timer_too_short' => 'El temporizador ha durado menos de lo que se redondea: no se ha imputado nada.',
    ],
    // Avisos de éxito (toasts) de las acciones de horas.
    'flash' => [
        'entry_created' => 'Horas guardadas: :minutes en «:task».',
        'entry_updated' => 'Entrada actualizada: :minutes en «:task».',
        'entry_deleted' => 'Entrada eliminada.',
        'timer_started' => 'Temporizador en marcha en «:task».',
        'timer_started_previous' => 'Temporizador en marcha en «:task». Se han imputado :minutes en «:previous».',
        'timer_stopped' => 'Temporizador parado: :minutes imputadas en «:task».',
        'timer_discarded' => 'Temporizador descartado. No se ha imputado nada.',
        'week_submitted' => 'Semana enviada para su aprobación.',
        'week_auto_approved' => 'Semana enviada y aprobada.',
        'week_withdrawn' => 'Semana retirada: vuelves a poder editarla.',
        'week_approved' => 'Semana de :name aprobada.',
        'weeks_approved' => '{1} Se ha aprobado 1 semana.|[2,*] Se han aprobado :count semanas.',
        'week_returned' => 'Semana de :name devuelta con tu comentario.',
        'week_reopened' => 'Semana reabierta: sus horas vuelven a ser editables.',
        'locked' => '{1} Se ha bloqueado 1 entrada.|[2,*] Se han bloqueado :count entradas.',
        'unlocked' => '{1} Se ha desbloqueado 1 entrada.|[2,*] Se han desbloqueado :count entradas.',
    ],
    // Notificaciones en la app (SPEC §13).
    'notifications' => [
        'approved_title' => 'Tus horas de la semana del :week están aprobadas',
        'approved_body' => 'Las ha aprobado :reviewer.',
        'returned_title' => 'Te han devuelto las horas de la semana del :week',
        'returned_body' => ':reviewer: «:comment»',
        'timer_long_title' => 'Tu temporizador lleva más de :hours horas en marcha',
        'timer_long_body' => '«:task». Si se te olvidó pararlo, páralo y ajusta la duración.',
    ],
    // Auditoría de los flujos (activity log).
    'activity' => [
        'submitted' => 'Semana enviada',
        'auto_approved' => 'Semana aprobada automáticamente',
        'approved' => 'Semana aprobada',
        'returned' => 'Semana devuelta',
        'withdrawn' => 'Semana retirada',
        'reopened' => 'Semana reabierta',
        'locked' => 'Horas bloqueadas',
        'unlocked' => 'Horas desbloqueadas',
    ],
    // Nombres de los campos en los errores de validación.
    'attributes' => [
        'task_id' => 'tarea',
        'user_id' => 'persona',
        'date' => 'fecha',
        'minutes' => 'duración',
        'start_time' => 'hora de inicio',
        'end_time' => 'hora de fin',
        'description' => 'descripción',
        'is_billable' => 'facturable',
        'week' => 'semana',
        'comment' => 'comentario',
        'periods' => 'semanas',
        'client_id' => 'cliente',
        'project_id' => 'proyecto',
        'date_from' => 'desde',
        'date_to' => 'hasta',
        'reference' => 'referencia',
    ],
];
