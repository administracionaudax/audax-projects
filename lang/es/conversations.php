<?php

/*
| Chat: conversaciones y mensajes (Fase 6, área C1). Se usan con __('conversations.…').
*/

return [
    'unknown_person' => 'persona',
    'untitled' => 'Conversación',
    'errors' => [
        'not_participant' => 'Solo quien participa en la conversación puede hacer esto.',
        'task_link' => 'Este mensaje no se puede enlazar a una tarea.',
        'task_exists' => 'Este mensaje ya tiene una tarea.',
        'task_not_project' => 'Las tareas solo se crean desde el chat de un proyecto.',
        'task_unavailable' => 'Este mensaje no está disponible: se ha borrado u ocultado.',
        'person_not_found' => 'Esa persona no existe o ya no está activa.',
        'message_not_here' => 'Ese mensaje no es de esta conversación.',
    ],
    'task' => [
        'untitled' => 'Mensaje de :name en el chat',
        'from_chat' => 'Creada desde un mensaje de :name en el chat del proyecto (:date).',
    ],
];
