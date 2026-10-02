<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | Transcripción de audios del chat (SPEC §12, D-070): whisper.cpp en el propio servidor
    | (contenedor audax-whisper, 127.0.0.1:18091). En local y en los tests, el motor falso.
    */
    'transcription' => [
        'driver' => env('TRANSCRIPTION_DRIVER', 'whisper'),
        'queue_connection' => env('TRANSCRIPTION_QUEUE_CONNECTION', 'redis-transcriptions'),
        'language' => env('TRANSCRIPTION_LANGUAGE', 'es'),
        'whisper' => [
            'url' => env('WHISPER_URL', 'http://127.0.0.1:18091'),
            'model' => env('WHISPER_MODEL', 'small'),
            // Menor que el timeout del job (2400 s).
            'timeout' => (int) env('WHISPER_TIMEOUT', 2340),
        ],
    ],

    /*
    | Web Push (SPEC §12 y §13, D-072). Las claves VAPID se generan en el servidor con
    | `php artisan push:vapid-keys` y se guardan en .env (nunca en Git). Sin claves válidas el
    | canal queda desactivado sin errores. Solo se envía a los servicios de push de los navegadores
    | (allowed_hosts): el endpoint lo manda el navegador y nunca puede apuntar a otro sitio (SSRF).
    */
    'webpush' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', 'mailto:no-responder@audaxstudio.com'),
        // Un aviso del chat que no se entrega en 12 horas ya no sirve.
        'ttl' => (int) env('WEBPUSH_TTL', 43200),
        'timeout' => (int) env('WEBPUSH_TIMEOUT', 10),
        'allowed_hosts' => ['googleapis.com', 'mozilla.com', 'push.apple.com', 'notify.windows.com'],
        // Suscripciones como mucho por persona (se borran las más antiguas).
        'max_per_user' => 10,
    ],

];
