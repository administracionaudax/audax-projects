<?php

/*
|--------------------------------------------------------------------------
| Notificaciones (SPEC §13, D-073)
|--------------------------------------------------------------------------
| Canal de Laravel de cada canal lógico de App\Domain\Notifications\NotificationCatalog.
| `app` es siempre `database` y `email` es siempre `mail`. `push` es la clase del canal de Web Push
| de la Fase 6 (D-072) y se fija al integrarla, solo si las claves VAPID están en el .env: mientras
| sea null, Web Push no se ofrece en /ajustes/notificaciones ni se envía.
*/

return [
    'channels' => [
        'push' => null,
    ],

    // Resumen diario (notifications:daily-digest): avisos sin leer de las últimas horas.
    'daily_digest' => [
        'hours' => 24,
        // Elementos que se citan por grupo en el email; del resto, «y N más».
        'items_per_group' => 10,
    ],
];
