/*
 * Service worker de Audax Proyectos (PWA básica, SPEC §3 y §17 Fase 0).
 *
 * Qué hace, y nada más:
 *   - Precarga /offline.html (página estática, sin datos).
 *   - Cache-first SOLO para los recursos versionados de Vite (/build/assets/*) y las fuentes.
 *   - Navegaciones: siempre a la red; si no hay red, se muestra /offline.html.
 *
 * Qué NO hace nunca:
 *   - Guardar HTML de la app, respuestas de Inertia (cabecera X-Inertia), JSON, /buscar, /health
 *     ni nada que dependa de la sesión. Esas peticiones ni siquiera pasan por respondWith().
 *
 * Para publicar cambios en este fichero, sube VERSION: al activarse se borran las cachés antiguas.
 * Interruptor de emergencia: sustituir este fichero por uno que llame a self.registration.unregister().
 */

const VERSION = 'v1';
const PREFIX = 'audax-';
const PRECACHE = `${PREFIX}precache-${VERSION}`;
const ASSETS = `${PREFIX}assets-${VERSION}`;
const OFFLINE_URL = '/offline.html';
const MAX_ASSETS = 120;

const NEVER = [
    /^\/buscar(?:\/|$)/,
    /^\/health(?:\/|$)/,
    /^\/api\//,
    /^\/horizon(?:\/|$)/,
];
const FONT = /\.(?:woff2?|ttf|otf)$/i;

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(PRECACHE)
            .then((cache) =>
                cache.add(new Request(OFFLINE_URL, { cache: 'reload' })),
            )
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            const keep = new Set([PRECACHE, ASSETS]);
            const keys = await caches.keys();

            await Promise.all(
                keys
                    .filter((key) => key.startsWith(PREFIX) && !keep.has(key))
                    .map((key) => caches.delete(key)),
            );

            await self.clients.claim();
        })(),
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    if (
        request.headers.has('X-Inertia') ||
        NEVER.some((pattern) => pattern.test(url.pathname))
    ) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(networkFirstNavigation(request));

        return;
    }

    if (isStaticAsset(url, request)) {
        event.respondWith(cacheFirst(request));
    }

    // Todo lo demás (JSON, imágenes de usuario, adjuntos…) va a la red sin intervenir.
});

function isStaticAsset(url, request) {
    return (
        url.pathname.startsWith('/build/assets/') ||
        request.destination === 'font' ||
        FONT.test(url.pathname)
    );
}

/** Navegación: red siempre (nunca se guarda el HTML); sin red, la página de "sin conexión". */
async function networkFirstNavigation(request) {
    try {
        return await fetch(request);
    } catch {
        const offline = await caches.match(OFFLINE_URL, {
            cacheName: PRECACHE,
        });

        return (
            offline ||
            new Response('Sin conexión', {
                status: 503,
                headers: { 'Content-Type': 'text/plain; charset=utf-8' },
            })
        );
    }
}

/** Recursos con hash en el nombre: inmutables, se sirven de caché si existen. */
async function cacheFirst(request) {
    const cache = await caches.open(ASSETS);
    const cached = await cache.match(request);

    if (cached) {
        return cached;
    }

    const response = await fetch(request);

    if (isCacheable(response)) {
        await cache.put(request, response.clone());
        await trim(cache);
    }

    return response;
}

function isCacheable(response) {
    if (
        !response ||
        !response.ok ||
        response.type !== 'basic' ||
        response.redirected
    ) {
        return false;
    }

    const type = response.headers.get('Content-Type') || '';
    const control = response.headers.get('Cache-Control') || '';

    return (
        !type.includes('text/html') &&
        !type.includes('json') &&
        !control.includes('no-store') &&
        !response.headers.has('X-Inertia')
    );
}

async function trim(cache) {
    const keys = await cache.keys();

    for (const key of keys.slice(0, Math.max(keys.length - MAX_ASSETS, 0))) {
        await cache.delete(key);
    }
}
