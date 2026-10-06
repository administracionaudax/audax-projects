<?php

use App\Domain\Privacy\Export\Sections\AbsencesSection;
use App\Domain\Privacy\Export\Sections\AiSummariesSection;
use App\Domain\Privacy\Export\Sections\AiUsageSection;
use App\Domain\Privacy\Export\Sections\ChatMessagesSection;
use App\Domain\Privacy\Export\Sections\DictationsSection;
use App\Domain\Privacy\Export\Sections\HelpLikesSection;
use App\Domain\Privacy\Export\Sections\IntegrationsSection;
use App\Domain\Privacy\Export\Sections\LoginEventsSection;
use App\Domain\Privacy\Export\Sections\MySpaceTasksSection;
use App\Domain\Privacy\Export\Sections\NotificationsSection;
use App\Domain\Privacy\Export\Sections\ProfileSection;
use App\Domain\Privacy\Export\Sections\SuggestionCommentsSection;
use App\Domain\Privacy\Export\Sections\SuggestionsSection;
use App\Domain\Privacy\Export\Sections\SuggestionVotesSection;
use App\Domain\Privacy\Export\Sections\TaskCommentsSection;
use App\Domain\Privacy\Export\Sections\TimeEntriesSection;
use App\Domain\Privacy\Export\Sections\WeeklyEntriesSection;
use App\Domain\Privacy\Export\Sections\WeeklyExemptionsSection;
use App\Domain\Privacy\Export\Sections\WeeklyRemindersSection;
use App\Domain\Privacy\Export\Sections\WeeklySubmissionsSection;
use App\Domain\Privacy\Export\Sections\WorkSchedulesSection;
use App\Domain\Privacy\Retention\ActivityLogPruner;
use App\Domain\Privacy\Retention\AiUsagePruner;
use App\Domain\Privacy\Retention\ChatMessagesPruner;
use App\Domain\Privacy\Retention\DictationsPruner;
use App\Domain\Privacy\Retention\LoginEventsPruner;
use App\Domain\Privacy\Retention\ReadNotificationsPruner;
use App\Domain\Privacy\Retention\WeeklyReminderLogsPruner;
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
        // La Weekly (Fase 10, 10.5, D-202): envíos, apuntes, dictados, exenciones, los resúmenes
        // con IA sobre la persona y los avisos que ha recibido.
        WeeklySubmissionsSection::class,
        WeeklyEntriesSection::class,
        DictationsSection::class,
        WeeklyExemptionsSection::class,
        AiSummariesSection::class,
        AiUsageSection::class,
        WeeklyRemindersSection::class,
        // Mi espacio (10.6, D-204): las tareas sugeridas sin crear y mi archivado personal.
        MySpaceTasksSection::class,
        // Centro de ayuda y sugerencias (10.7, D-211): lo que has publicado, comentado, votado y
        // los «me gusta» a las novedades.
        SuggestionsSection::class,
        SuggestionCommentsSection::class,
        SuggestionVotesSection::class,
        HelpLikesSection::class,
        NotificationsSection::class,
        // Cuenta de Google conectada (Fase 9, D-142): el correo y la fecha, nunca los tokens.
        IntegrationsSection::class,
        LoginEventsSection::class,
    ],

    // Qué borra app:prune-data para cada plazo de RetentionPolicy (tipo → clase que implementa
    // App\Domain\Privacy\Retention\RetentionPruner). Un tipo sin clase no se borra.
    'pruners' => [
        RetentionPolicy::LOGIN_EVENTS => LoginEventsPruner::class,
        RetentionPolicy::READ_NOTIFICATIONS => ReadNotificationsPruner::class,
        RetentionPolicy::ACTIVITY_LOG => ActivityLogPruner::class,
        RetentionPolicy::CHAT_MESSAGES => ChatMessagesPruner::class,
        // La Weekly (10.5, D-202): el registro de avisos y los dictados (borradores de texto).
        RetentionPolicy::WEEKLY_REMINDER_LOGS => WeeklyReminderLogsPruner::class,
        RetentionPolicy::DICTATIONS => DictationsPruner::class,
        RetentionPolicy::AI_USAGE => AiUsagePruner::class,
    ],

    // Filas por lote al borrar: cada lote es una sentencia corta, sin bloqueos largos.
    'prune_batch_size' => 1000,

    // Horas que puede estar una exportación en cola o preparándose antes de darla por fallida.
    'stuck_export_hours' => 24,
];
