<?php

/*
| Chat (Fase 6). Se usan con __('chat.…').
*/

return [
    'errors' => [
        'empty' => 'Escribe algo o adjunta un archivo.',
        'too_long' => 'El mensaje no puede pasar de :max caracteres.',
        'parent' => 'El mensaje al que respondes no es de esta conversación.',
        'emoji' => 'Esa reacción no es un emoji válido.',
        'direct_invalid' => 'Solo puedes escribir a otra persona de la plantilla que esté activa.',
        'group_invalid' => 'Un grupo necesita un nombre y al menos otra persona activa.',
        'group_name' => 'El grupo necesita un nombre.',
        'group_add' => 'Elige al menos una persona activa que aún no esté en el grupo.',
        'group_remove' => 'Esa persona no está en el grupo (para irte tú, usa «Salir del grupo»).',
    ],
    'notifications' => [
        'transcriptions_failing' => [
            'title' => '{1} Un audio del chat sigue sin transcribir|[2,*] :count audios del chat siguen sin transcribir',
            'body' => 'Se han agotado los reintentos automáticos. Revisa el transcriptor y relánzalos desde Transcripciones.',
        ],
    ],
];
