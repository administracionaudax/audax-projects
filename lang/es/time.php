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
        'bank_department' => 'La bolsa «:bank» es solo para el departamento :department.',
        'bank_blocked' => 'La bolsa «:bank» no admite exceso. Saldo disponible: :available.',
        'not_member' => 'Solo los miembros del proyecto pueden imputar horas.',
        'future_date' => 'No se pueden imputar horas en fechas futuras.',
        'week_closed' => 'La semana del :week está :status. Hay que reabrirla para cambiar sus horas.',
        'minutes_range' => 'La duración debe estar entre 0:01 y 24:00.',
        'day_over_24h' => 'Con esta entrada, el :date suma más de 24 horas.',
        'description_required' => 'Escribe una descripción de lo que has hecho.',
        'timer_bank_empty' => 'La bolsa «:bank» no tiene saldo y no admite exceso: no se puede iniciar el temporizador.',
        'no_timer' => 'No tienes ningún temporizador en marcha.',
    ],
    'warnings' => [
        'task_completed' => 'La tarea ya está completada.',
        'over_capacity' => 'Ese día sumas :total, más de un 25 % por encima de tu jornada (:capacity).',
        'no_capacity' => 'Ese día no tienes jornada y sumas :total.',
        'overage_all' => 'Esta bolsa está agotada: estas horas se registrarán como exceso.',
        'overage_partial' => ':minutes de esta entrada se registrarán como exceso: la bolsa se agota.',
        'timer_too_short' => 'El temporizador ha durado menos de lo que se redondea: no se ha imputado nada.',
    ],
];
