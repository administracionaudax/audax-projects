<?php

/*
| Tiempo real, avisos del chat y Web Push (Fase 6, área C2). Se usan con __('realtime.…').
*/

return [
    'notifications' => [
        'mention' => ':actor te ha mencionado en «:conversation»',
        'everyone' => ':actor ha avisado a todos en «:conversation»',
        'direct' => ':actor te ha escrito',
    ],
    'excerpt' => [
        'audio' => 'Mensaje de voz',
        'file' => 'Archivo adjunto',
        'someone' => 'alguien',
    ],
    'push' => [
        'disabled' => 'Los avisos del navegador no están configurados en el servidor.',
        'endpoint' => 'Ese navegador no tiene un servicio de avisos admitido.',
        'keys' => 'Las claves de la suscripción del navegador no son válidas.',
    ],
    'vapid' => [
        'generated' => 'Claves VAPID nuevas. Cópialas en el .env del servidor (shared/.env) y no las subas a Git:',
        'warning' => 'Si cambias unas claves que ya estaban en uso, los navegadores suscritos tendrán que volver a activar los avisos.',
        'valid' => 'Las claves VAPID de la configuración son válidas: los avisos del navegador están activos.',
        'invalid' => 'No hay claves VAPID válidas en la configuración: los avisos del navegador están desactivados.',
    ],
];
