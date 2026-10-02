<?php

use App\Broadcasting\WebPushChannel;
use App\Broadcasting\WebPushConfig;

/*
|--------------------------------------------------------------------------
| Notificaciones (SPEC §13, D-073)
|--------------------------------------------------------------------------
| Canal de Laravel de cada canal lógico de App\Domain\Notifications\NotificationCatalog.
| `app` es siempre `database` y `email` es siempre `mail`. `push` es el canal de Web Push de la
| Fase 6 (WebPushChannel, D-072) solo si las claves VAPID del .env son válidas (las mismas
| comprobaciones que WebPushConfig); si no, null: Web Push no se ofrece en /ajustes/notificaciones
| ni se envía. Se decide al cargar la configuración (con config:cache, al desplegar).
*/

return [
    'channels' => [
        'push' => WebPushConfig::fromValues(
            env('VAPID_PUBLIC_KEY'),
            env('VAPID_PRIVATE_KEY'),
            env('VAPID_SUBJECT', 'mailto:no-responder@audaxstudio.com'),
        ) !== null ? WebPushChannel::class : null,
    ],

    // Resumen diario (notifications:daily-digest): avisos sin leer de las últimas horas.
    'daily_digest' => [
        'hours' => 24,
        // Elementos que se citan por grupo en el email; del resto, «y N más».
        'items_per_group' => 10,
    ],
];
