/*
 * Service worker de Audax Proyectos (PWA básica, SPEC §3 y §17 Fase 0).
 *
 * Qué hace, y nada más:
 *   - Precarga /offline.html (página estática, sin datos).
 *   - Cache-first SOLO para los recursos versionados de Vite (/build/assets/*) y las fuentes.
 *   - Navegaciones: siempre a la red; si no hay red, se muestra /offline.html.
 *   - Avisos del navegador (Web Push, Fase 6, D-072): pinta los avisos que envía el servidor
 *     (llegan cifrados; solo título, texto, enlace y etiqueta) y, al pulsarlos, abre la
 *     conversación en una pestaña de la app. Si el navegador renueva la suscripción, la vuelve
 *     a registrar en el servidor.
 *
 * Qué NO hace nunca:
 *   - Guardar HTML de la app, respuestas de Inertia (cabecera X-Inertia), JSON, /buscar, /health
 *     ni nada que dependa de la sesión. Esas peticiones ni siquiera pasan por respondWith().
 *
 * Para publicar cambios en este fichero, sube VERSION: al activarse se borran las cachés antiguas.
 * Interruptor de emergencia: sustituir este fichero por uno que llame a self.registration.unregister().
 */

const VERSION = 'v2';
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

/* ------------------------------------------------------------------------------------------ *
 * Avisos del navegador (Web Push, D-072)
 * ------------------------------------------------------------------------------------------ */

const PUSH_DEFAULT_URL = '/chat';
const PUSH_SUBSCRIPTIONS_URL = '/avisos-navegador/suscripciones';

/**
 * Solo rutas relativas de la propia app (nunca otra web: redirección abierta). Se resuelve como lo
 * haría el navegador (que quita tabuladores y saltos de línea y trata «\\» como «/»: «/\t/x.com» o
 * «/\\x.com» acabarían en otra web) y se exige el mismo origen; se devuelve la ruta ya resuelta.
 */
function pushAppUrl(value) {
    if (typeof value !== 'string' || !value.startsWith('/')) {
        return PUSH_DEFAULT_URL;
    }

    let url;

    try {
        url = new URL(value, self.location.origin);
    } catch {
        return PUSH_DEFAULT_URL;
    }

    if (url.origin !== self.location.origin) {
        return PUSH_DEFAULT_URL;
    }

    return `${url.pathname}${url.search}${url.hash}`;
}

function pushText(value, max) {
    return typeof value === 'string' ? value.slice(0, max) : '';
}

function readPushData(event) {
    if (!event.data) {
        return {};
    }

    try {
        const data = event.data.json();

        return data && typeof data === 'object' ? data : {};
    } catch {
        return { body: event.data.text() };
    }
}

self.addEventListener('push', (event) => {
    const data = readPushData(event);
    const tag = pushText(data.tag, 64);
    const options = {
        body: pushText(data.body, 240),
        icon: '/icons/icon-192.png',
        lang: 'es',
        dir: 'ltr',
        data: { url: pushAppUrl(data.url) },
    };

    // La etiqueta agrupa los avisos de una misma conversación: el nuevo sustituye al anterior.
    if (tag) {
        options.tag = tag;
        options.renotify = true;
    }

    event.waitUntil(
        self.registration.showNotification(
            pushText(data.title, 120) || 'Audax Proyectos',
            options,
        ),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const data = event.notification.data || {};
    const url = new URL(pushAppUrl(data.url), self.location.origin).href;

    event.waitUntil(openFromNotification(url));
});

/** Reutiliza una pestaña de la app si hay alguna abierta; si no, abre una nueva. */
async function openFromNotification(url) {
    const windows = await self.clients.matchAll({
        type: 'window',
        includeUncontrolled: true,
    });
    const client = windows.find(
        (candidate) => new URL(candidate.url).origin === self.location.origin,
    );

    if (client) {
        try {
            const focused = await client.focus();
            const navigated = await (focused || client).navigate(url);

            if (navigated) {
                return;
            }
        } catch {
            // Pestaña sin control del service worker: se abre otra.
        }
    }

    await self.clients.openWindow(url);
}

self.addEventListener('pushsubscriptionchange', (event) => {
    event.waitUntil(renewPushSubscription(event));
});

/** El navegador ha cambiado la suscripción: se registra la nueva con la sesión de la app. */
async function renewPushSubscription(event) {
    const previous = event.oldSubscription || null;
    let next = event.newSubscription || null;

    if (!next && previous && previous.options) {
        next = await self.registration.pushManager.subscribe(previous.options);
    }

    if (!next) {
        return;
    }

    const json = next.toJSON();
    const headers = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };

    await fetch(PUSH_SUBSCRIPTIONS_URL, {
        method: 'POST',
        credentials: 'same-origin',
        headers,
        body: JSON.stringify({
            endpoint: json.endpoint,
            keys: json.keys,
            content_encoding: 'aes128gcm',
        }),
    }).catch(() => undefined);

    if (previous && previous.endpoint !== json.endpoint) {
        await fetch(PUSH_SUBSCRIPTIONS_URL, {
            method: 'DELETE',
            credentials: 'same-origin',
            headers,
            body: JSON.stringify({ endpoint: previous.endpoint }),
        }).catch(() => undefined);
    }
}
