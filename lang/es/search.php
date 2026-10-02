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
        'projects' => ['title' => 'Proyectos', 'subtitle' => 'Proyectos y tareas', 'keywords' => 'proyecto'],
        'clients' => ['title' => 'Clientes', 'subtitle' => 'Clientes de Audax Studio', 'keywords' => 'cliente empresa'],
        'hour_banks' => ['title' => 'Bolsas', 'subtitle' => 'Bolsas de horas', 'keywords' => 'bolsas de horas consumo'],
        'time' => ['title' => 'Horas', 'subtitle' => 'Imputación de horas', 'keywords' => 'imputar temporizador hoja semanal'],
        'workload' => ['title' => 'Carga', 'subtitle' => 'Capacidad y carga de trabajo', 'keywords' => 'capacidad planificacion ausencias'],
        'reports' => ['title' => 'Informes', 'subtitle' => 'Informes y dashboards', 'keywords' => 'dashboard productividad rentabilidad'],
        'chat' => ['title' => 'Chat', 'subtitle' => 'Conversaciones', 'keywords' => 'mensajes conversaciones'],
        'admin' => ['title' => 'Administración', 'subtitle' => 'Usuarios, departamentos y ajustes', 'keywords' => 'admin usuarios departamentos ajustes configuracion'],
        'profile' => ['title' => 'Perfil', 'subtitle' => 'Ajustes', 'keywords' => 'ajustes nombre correo'],
        'security' => ['title' => 'Seguridad', 'subtitle' => 'Ajustes', 'keywords' => 'ajustes contrasena 2fa doble factor'],
        'appearance' => ['title' => 'Apariencia', 'subtitle' => 'Ajustes', 'keywords' => 'ajustes tema claro oscuro'],
        'sessions' => ['title' => 'Sesiones activas', 'subtitle' => 'Ajustes', 'keywords' => 'ajustes dispositivos cerrar sesion'],
        'audit' => ['title' => 'Auditoría', 'subtitle' => 'Registro de cambios: quién, qué y cuándo', 'keywords' => 'auditoria registro actividad cambios historial'],
        'privacy_admin' => ['title' => 'Privacidad (administración)', 'subtitle' => 'Texto informativo, conservación de datos y avisos', 'keywords' => 'rgpd retencion conservacion texto informativo disco copias'],
        'privacy' => ['title' => 'Privacidad', 'subtitle' => 'Cómo se tratan tus datos', 'keywords' => 'rgpd proteccion de datos personales aviso'],
        'my_data' => ['title' => 'Mis datos', 'subtitle' => 'Ajustes', 'keywords' => 'ajustes exportar descargar datos personales rgpd copia'],
    ],
];
