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
    | PDF de los informes (Fase 9, D-140): el HTML con la hoja de documentos de Audax se convierte
    | con Gotenberg (Chromium en Docker, contenedor audax-gotenberg, solo en 127.0.0.1:18092).
    | REPORTS_PDF_DRIVER=html devuelve el HTML sin convertir: tests y desarrollo local sin Docker.
    | El timeout del cliente es algo mayor que el --api-timeout de Gotenberg (60 s), para recibir su
    | 503 con el motivo en vez de cortar la conexión.
    */
    'gotenberg' => [
        'url' => env('GOTENBERG_URL', 'http://127.0.0.1:18092'),
        'timeout' => (int) env('GOTENBERG_TIMEOUT', 65),
    ],

    'reports_pdf' => [
        'driver' => env('REPORTS_PDF_DRIVER', 'gotenberg'),
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
    | IA externa de la Weekly (Fase 10, D-146): solo TEXTO a Google Gemini por la API de AI Studio,
    | con una clave de pago (Google no entrena con esos datos). La clave y el modelo van en el .env
    | del servidor (nunca en Git): GEMINI_MODEL se cambia sin desplegar cuando Google retira uno
    | (F-174). Se llama siempre desde la cola `ai` (App\Domain\Weeklies\Ai\AiQueue). En los tests y
    | en local sin clave, GEMINI_DRIVER=fake (App\Domain\Weeklies\Ai\FakeLlm).
    | pricing: USD por millón de tokens (entrada y salida), para el coste estimado de ai_usage.
    */
    'gemini' => [
        'driver' => env('GEMINI_DRIVER', 'gemini'),
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        // Por intento; un Job de la cola `ai` puede durar hasta 600 s.
        'timeout' => (int) env('GEMINI_TIMEOUT', 120),
        'tries' => (int) env('GEMINI_TRIES', 3),
        // Peticiones por persona y día (hora de Madrid) que llegan a Gemini (D-222, AiDailyLimits);
        // 0 = sin límite. El informe semanal, su audio y la satisfacción del cierre no cuentan.
        'daily_limits' => [
            'assistant' => (int) env('AI_DAILY_LIMIT_ASSISTANT', 60),
            'summaries' => (int) env('AI_DAILY_LIMIT_SUMMARIES', 30),
            'suggested_tasks' => (int) env('AI_DAILY_LIMIT_SUGGESTED_TASKS', 10),
        ],
        'pricing' => [
            'gemini-3.5-flash' => ['input' => '1.5', 'output' => '9.0'],
            'gemini-2.5-flash' => ['input' => '0.3', 'output' => '2.5'],
            'gemini-2.5-flash-lite' => ['input' => '0.1', 'output' => '0.4'],
            'gemini-2.0-flash' => ['input' => '0.1', 'output' => '0.4'],
        ],
    ],

    /*
    | Locución del informe semanal (Fase 10, D-146, F-084): Google Cloud Text-to-Speech con su propia
    | clave de API (GOOGLE_TTS_API_KEY, en el .env del servidor). Sin clave, el audio no se genera.
    | En los tests, GOOGLE_TTS_DRIVER=fake.
    */
    'google_tts' => [
        'driver' => env('GOOGLE_TTS_DRIVER', 'google'),
        'key' => env('GOOGLE_TTS_API_KEY'),
        'voice' => env('GOOGLE_TTS_VOICE', 'es-ES-Journey-F'),
        'language' => env('GOOGLE_TTS_LANGUAGE', 'es-ES'),
        'base_url' => env('GOOGLE_TTS_BASE_URL', 'https://texttospeech.googleapis.com/v1'),
        'timeout' => (int) env('GOOGLE_TTS_TIMEOUT', 60),
        // USD por millón de caracteres (WeeklySync estimaba con la tarifa Neural2).
        'price_per_million_chars' => env('GOOGLE_TTS_PRICE_PER_MILLION_CHARS', '16'),
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

    /*
    | Google Sheets (Fase 9, D-142): cliente OAuth 2.0 de tipo «Interno» del proyecto de Google
    | Cloud `audax-proyectos`. El ID y el secreto los pone el propietario en el .env del servidor
    | (nunca en Git). Sin ellos, la exportación a Google Sheets no se ofrece. La URI de redirección
    | debe ser exactamente la autorizada en Google Cloud; vacía, se usa la ruta
    | integrations.google.callback de APP_URL. Solo se aceptan cuentas del dominio de Workspace.
    |
    | Acceso con Google (D-165): el mismo cliente con su propia URI de redirección
    | (GOOGLE_LOGIN_REDIRECT_URI; vacía, la ruta login.google.callback de APP_URL) y los dominios
    | de Workspace permitidos (GOOGLE_LOGIN_DOMAINS, separados por comas). Se activa o desactiva en
    | /admin/ajustes (google_login_enabled).
    */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
        'hosted_domain' => env('GOOGLE_HOSTED_DOMAIN', 'audaxstudio.com'),
        'timeout' => (int) env('GOOGLE_TIMEOUT', 30),
        'login_redirect' => env('GOOGLE_LOGIN_REDIRECT_URI'),
        'login_domains' => env('GOOGLE_LOGIN_DOMAINS', 'audaxstudio.com'),
    ],

];
