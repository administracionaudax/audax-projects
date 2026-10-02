<?php

use App\Domain\Privacy\Export\Sections\AbsencesSection;
use App\Domain\Privacy\Export\Sections\ChatMessagesSection;
use App\Domain\Privacy\Export\Sections\LoginEventsSection;
use App\Domain\Privacy\Export\Sections\NotificationsSection;
use App\Domain\Privacy\Export\Sections\ProfileSection;
use App\Domain\Privacy\Export\Sections\TaskCommentsSection;
use App\Domain\Privacy\Export\Sections\TimeEntriesSection;
use App\Domain\Privacy\Export\Sections\WorkSchedulesSection;
use App\Domain\Privacy\Retention\ActivityLogPruner;
use App\Domain\Privacy\Retention\ChatMessagesPruner;
use App\Domain\Privacy\Retention\LoginEventsPruner;
use App\Domain\Privacy\Retention\ReadNotificationsPruner;
use App\Domain\Privacy\RetentionPolicy;

/*
|--------------------------------------------------------------------------
| Privacidad y RGPD (SPEC §15, D-075): puntos de extensión
|--------------------------------------------------------------------------
| Con la Fase 6 (chat) integrada:
| - el ZIP de datos personales lleva los mensajes propios y las transcripciones de los audios
|   propios (ChatMessagesSection),
| - RetentionPolicy::CHAT_MESSAGES borra los mensajes antiguos con sus adjuntos, audios y
|   transcripciones (ChatMessagesPruner); sin límite por defecto.
*/

return [
    // Secciones del ZIP de datos personales, en este orden. Cada clase implementa
    // App\Domain\Privacy\Export\PersonalDataSection (un JSON y un CSV por sección).
    'export_sections' => [
        ProfileSection::class,
        WorkSchedulesSection::class,
        TimeEntriesSection::class,
        AbsencesSection::class,
        TaskCommentsSection::class,
        ChatMessagesSection::class,
        NotificationsSection::class,
        LoginEventsSection::class,
    ],

    // Qué borra app:prune-data para cada plazo de RetentionPolicy (tipo → clase que implementa
    // App\Domain\Privacy\Retention\RetentionPruner). Un tipo sin clase no se borra.
    'pruners' => [
        RetentionPolicy::LOGIN_EVENTS => LoginEventsPruner::class,
        RetentionPolicy::READ_NOTIFICATIONS => ReadNotificationsPruner::class,
        RetentionPolicy::ACTIVITY_LOG => ActivityLogPruner::class,
        RetentionPolicy::CHAT_MESSAGES => ChatMessagesPruner::class,
    ],

    // Filas por lote al borrar: cada lote es una sentencia corta, sin bloqueos largos.
    'prune_batch_size' => 1000,

    // Horas que puede estar una exportación en cola o preparándose antes de darla por fallida.
    'stuck_export_hours' => 24,
];
