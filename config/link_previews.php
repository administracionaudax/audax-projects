<?php

/*
|--------------------------------------------------------------------------
| Previsualización de enlaces del chat (Fase 6, D-069)
|--------------------------------------------------------------------------
| Al publicar un mensaje, un job (cola default) resuelve su primer enlace http(s) con protección
| SSRF estricta (App\Domain\Chat\Links\LinkPreviewFetcher): solo puertos 80 y 443, la IP se
| comprueba ANTES de conectar y en cada redirección (nada privado, local, link-local, multicast
| ni reservado, IPv4 e IPv6), se conecta a esa misma IP, 3 redirecciones como mucho, 3 s en total,
| 512 KB y solo text/html. Se guarda título, descripción y dominio, nunca una imagen remota.
| En los tests está apagado (phpunit.xml): ningún test sale a la red por un mensaje con enlace.
*/

return [
    'enabled' => (bool) env('LINK_PREVIEWS_ENABLED', true),
    // Segundos para toda la resolución (conexión, redirecciones y lectura).
    'timeout' => 3,
    'max_bytes' => 512 * 1024,
    'max_redirects' => 3,
    // Horas que se recuerda la previsualización de una URL (y 1 h si no se pudo resolver).
    'cache_hours' => 24,
    'user_agent' => 'AudaxProyectos-LinkPreview/1.0 (+https://projects.audaxstudio.com)',
];
