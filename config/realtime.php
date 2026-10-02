<?php

/*
|--------------------------------------------------------------------------
| Tiempo real para el navegador (Fase 6, D-068)
|--------------------------------------------------------------------------
| El frontend se compila en el Mac, así que Echo NO lee el host de Reverb de variables VITE_:
| recibe estos valores en la prop compartida `realtime` (solo usuarios internos y solo si el
| broadcaster es reverb). En el servidor, el navegador conecta por 443 a projects.audaxstudio.com
| y nginx pasa /app/ a Reverb en 127.0.0.1:18080 (RUNBOOK A1). La clave de la app es pública
| (identifica la app ante Reverb); el secreto nunca sale del servidor.
*/

return [
    'enabled' => env('BROADCAST_CONNECTION') === 'reverb',
    'key' => env('REVERB_APP_KEY'),
    'host' => env('REVERB_CLIENT_HOST', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost'),
    'port' => (int) env('REVERB_CLIENT_PORT', 443),
    'scheme' => env('REVERB_CLIENT_SCHEME', 'https'),
];
