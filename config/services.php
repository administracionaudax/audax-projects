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
            // Menor que el timeout del job (900 s).
            'timeout' => (int) env('WHISPER_TIMEOUT', 870),
        ],
    ],

];
