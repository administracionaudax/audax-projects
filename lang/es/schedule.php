<?php

/*
| Gantt, dependencias, plantillas y tareas recurrentes (Fase 4). Se usan con __('schedule.…').
*/

return [
    'errors' => [
        'self' => 'Una tarea no puede depender de sí misma.',
        'other_project' => 'Solo se pueden enlazar tareas del mismo proyecto.',
        'cycle' => 'Esa dependencia crearía un ciclo: la tarea ya depende, directa o indirectamente, de la otra.',
        'template_invalid' => 'La plantilla no es válida: :reason',
    ],
];
