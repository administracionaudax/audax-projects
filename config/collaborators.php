<?php

/*
|--------------------------------------------------------------------------
| Colaboradores externos (Fase 8, D-134): rutas internas permitidas
|--------------------------------------------------------------------------
| Las rutas internas están CERRADAS por defecto para el rol `collaborator`: el middleware
| `collaborator` (App\Http\Middleware\RestrictCollaborators) responde 403 en cualquier ruta cuyo
| nombre no encaje con uno de estos patrones (Str::is, `*` comodín). Abrir una ruta nueva al
| colaborador es añadirla aquí y comprobar que su política y sus consultas usan
| User::visibleProjectIds() / canSeeProject() (lo vigila tests/Feature/Collaborators).
|
| Fuera de la lista quedan, entre otras: clientes, bolsas, informes, carga, planificación general,
| Gantt global, ausencias, plantillas, tareas recurrentes, acceso al portal, auditoría,
| administración, aprobaciones y bloqueo de horas, la pestaña Horas del proyecto (las de todos),
| los ajustes, miembros y gestores del proyecto, las directas y los grupos del chat y la moderación.
*/

return [
    'routes' => [
        // Inicio (y el orden de sus tarjetas, D-138) y Mis tareas (solo de sus proyectos).
        'home',
        'home.layout.update',
        'home.layout.destroy',
        // Secciones plegadas de su barra lateral (D-260): una preferencia propia, sin datos de nadie.
        'nav.sections.update',
        'dashboard',
        // Versión de la interfaz (F-013): sin datos, solo el número del despliegue.
        'app.version',
        'my-tasks.index',
        // Calendario del equipo (D-144): solo las tareas y las personas de sus proyectos.
        'calendar.index',

        // Proyectos: listado y ficha con las pestañas Resumen, Tareas, Gantt del proyecto, Chat y Archivos.
        'projects.index',
        'projects.show',
        'projects.tasks',
        'projects.files',
        'projects.chat',
        'gantt.project',

        // Tareas y sus subrecursos (como cualquier miembro, TaskPolicy).
        'tasks.store',
        'tasks.bulk',
        'tasks.show',
        'tasks.update',
        'tasks.destroy',
        'tasks.position',
        'tasks.move',
        'tasks.watch',
        'tasks.unwatch',
        'tasks.comments.*',
        'tasks.attachments.store',
        'attachments.show',
        'attachments.thumbnail',
        'attachments.destroy',
        'schedule.dependencies.store',
        'schedule.dependencies.destroy',
        'schedule.reschedule.preview',
        'schedule.reschedule.store',
        'planning.dependencies.candidates',

        // Horas propias: hoja semanal, entradas, buscador de tareas, opciones y temporizador.
        'time.index',
        'time.week.submit',
        'time.week.withdraw',
        'time.entries.store',
        'time.entries.update',
        'time.entries.destroy',
        'time.tasks',
        'time.options',
        'timer.start',
        'timer.stop',
        'timer.discard',

        // Notificaciones y sus ajustes.
        'notifications.*',
        'notification-settings.*',

        // Chat de sus proyectos (sin directas, grupos ni moderación) y tiempo real.
        'chat.index',
        'chat.conversations',
        'chat.show',
        'chat.pinned',
        'chat.read',
        'chat.mute',
        'chat.search',
        'chat.messages.index',
        'chat.messages.poll',
        'chat.messages.store',
        'chat.messages.show',
        'chat.messages.update',
        'chat.messages.destroy',
        'chat.messages.react',
        'chat.messages.pin',
        'chat.messages.task.options',
        'chat.messages.task.store',
        'chat.media.store',
        'chat.media.audio',
        'chat.media.transcriptions',
        'realtime.*',
        'push.*',

        // Búsqueda global (solo proyectos, tareas, mensajes y páginas que ve).
        'search',

        // Privacidad propia y ajustes personales (perfil, contraseña, 2FA, sesiones y apariencia).
        'privacy.show',
        'privacy.acknowledge',
        'privacy.exports.index',
        'privacy.exports.store',
        'privacy.exports.download',
        'profile.edit',
        'profile.update',
        'security.edit',
        'user-password.update',
        'appearance.edit',
        'appearance.update',
        'sessions.*',
    ],

    // Rutas sin nombre permitidas, por URI: la autorización de los canales de tiempo real
    // (routes/channels.php filtra cada canal) y la redirección /ajustes → /ajustes/perfil.
    'uris' => [
        'broadcasting/auth',
        'ajustes',
    ],
];
