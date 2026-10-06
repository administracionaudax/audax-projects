<?php

/*
|--------------------------------------------------------------------------
| Búsqueda global (Ctrl/Cmd + K)
|--------------------------------------------------------------------------
| Secciones que devuelve App\Search\Sources\PageSource. "keywords" son términos de búsqueda
| adicionales (sin acentos: la comparación los ignora).
*/

return [
    'results' => [
        'projects' => '{0} Sin proyectos activos|{1} :count proyecto activo|[2,*] :count proyectos activos',
        'inactive' => 'Inactivo',
        'internal' => 'Interno',
        'archived' => 'Archivado',
        'completed' => 'Completada',
    ],
    'pages' => [
        'home' => ['title' => 'Inicio', 'subtitle' => 'Tu panel personal', 'keywords' => 'panel dashboard resumen'],
        'my_tasks' => ['title' => 'Mis tareas', 'subtitle' => 'Tareas asignadas a ti', 'keywords' => 'tareas pendientes'],
        'calendar' => ['title' => 'Calendario', 'subtitle' => 'Tareas del equipo por día, semana o mes', 'keywords' => 'calendario agenda equipo personas fechas hitos'],
        'projects' => ['title' => 'Proyectos', 'subtitle' => 'Proyectos y tareas', 'keywords' => 'proyecto'],
        'clients' => ['title' => 'Clientes', 'subtitle' => 'Clientes de Audax Studio', 'keywords' => 'cliente empresa'],
        'hour_banks' => ['title' => 'Bolsas', 'subtitle' => 'Bolsas de horas', 'keywords' => 'bolsas de horas consumo'],
        'time' => ['title' => 'Horas', 'subtitle' => 'Imputación de horas', 'keywords' => 'imputar temporizador hoja semanal'],
        'workload' => ['title' => 'Carga', 'subtitle' => 'Capacidad y carga de trabajo', 'keywords' => 'capacidad planificacion sobrecarga reparto'],
        'absences' => ['title' => 'Ausencias', 'subtitle' => 'Tus vacaciones, permisos y bajas', 'keywords' => 'mis ausencias vacaciones permiso baja solicitar dias libres'],
        'team_absences' => ['title' => 'Ausencias del equipo', 'subtitle' => 'Aprobar y registrar las ausencias de tu equipo', 'keywords' => 'aprobar solicitudes pendientes vacaciones equipo calendario'],
        'reports' => ['title' => 'Informes', 'subtitle' => 'Informes y dashboards', 'keywords' => 'dashboard productividad rentabilidad'],
        'chat' => ['title' => 'Chat', 'subtitle' => 'Conversaciones', 'keywords' => 'mensajes conversaciones'],
        'admin' => ['title' => 'Administración', 'subtitle' => 'Usuarios, departamentos y ajustes', 'keywords' => 'admin usuarios departamentos ajustes configuracion'],
        'holidays' => ['title' => 'Festivos', 'subtitle' => 'Calendario de festivos de la agencia', 'keywords' => 'festivos calendario laboral nacionales importar'],
        'profile' => ['title' => 'Perfil', 'subtitle' => 'Ajustes', 'keywords' => 'ajustes nombre correo'],
        'security' => ['title' => 'Seguridad', 'subtitle' => 'Ajustes', 'keywords' => 'ajustes contrasena 2fa doble factor'],
        'appearance' => ['title' => 'Apariencia', 'subtitle' => 'Ajustes', 'keywords' => 'ajustes tema claro oscuro'],
        'notification_settings' => ['title' => 'Preferencias de notificación', 'subtitle' => 'Ajustes', 'keywords' => 'ajustes notificaciones avisos email correo resumen diario campana navegador'],
        'sessions' => ['title' => 'Sesiones activas', 'subtitle' => 'Ajustes', 'keywords' => 'ajustes dispositivos cerrar sesion'],
        'audit' => ['title' => 'Auditoría', 'subtitle' => 'Registro de cambios: quién, qué y cuándo', 'keywords' => 'auditoria registro actividad cambios historial'],
        'privacy_admin' => ['title' => 'Privacidad (administración)', 'subtitle' => 'Texto informativo, conservación de datos y avisos', 'keywords' => 'rgpd retencion conservacion texto informativo disco copias'],
        'privacy' => ['title' => 'Privacidad', 'subtitle' => 'Cómo se tratan tus datos', 'keywords' => 'rgpd proteccion de datos personales aviso'],
        'my_data' => ['title' => 'Mis datos', 'subtitle' => 'Ajustes', 'keywords' => 'ajustes exportar descargar datos personales rgpd copia'],
        // La Weekly (Fase 10, D-239).
        'my_space' => ['title' => 'Mi espacio', 'subtitle' => 'Weekly', 'keywords' => 'weekly mi weekly semana escribir enviar dictado'],
        'weeklies' => ['title' => 'Weeklies', 'subtitle' => 'Weekly', 'keywords' => 'weekly semanas informe historico cerrar'],
        'weekly_team' => ['title' => 'Equipo', 'subtitle' => 'Weekly', 'keywords' => 'weekly plantilla personas estado envios racha'],
        'assistant' => ['title' => 'Asistente IA', 'subtitle' => 'Weekly', 'keywords' => 'weekly ia inteligencia artificial preguntas asistente'],
        'help' => ['title' => 'Ayuda', 'subtitle' => 'Weekly', 'keywords' => 'weekly ayuda tutoriales preguntas frecuentes manual novedades sugerencias'],
        // Plan del día (D-250).
        'day_plan' => ['title' => 'Mi día', 'subtitle' => 'Plan del día', 'keywords' => 'plan del dia hoy daily lista lineas pendientes'],
        'day_plan_team' => ['title' => 'Equipo hoy', 'subtitle' => 'Plan del día', 'keywords' => 'plan del dia equipo hoy daily quien sin plan'],
        'day_plan_week' => ['title' => 'Semana del equipo', 'subtitle' => 'Plan del día', 'keywords' => 'plan del dia semana equipo daily'],
    ],
];
