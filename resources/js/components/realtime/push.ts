import { realtimeRequest } from '@/hooks/use-realtime-http';
import {
    show as pushShowRoute,
    subscribe as pushSubscribeRoute,
    unsubscribe as pushUnsubscribeRoute,
} from '@/routes/push';

/**
 * Avisos del navegador (Web Push, D-072), lado del navegador. El service worker de la PWA
 * (public/sw.js) los pinta y, al pulsarlos, abre la conversación.
 *
 * - Activar: permiso del navegador → suscripción con la clave pública VAPID del servidor →
 *   POST /avisos-navegador/suscripciones. Se recuerda en este navegador qué persona los activó.
 * - Desactivar: DELETE en el servidor y baja de la suscripción del navegador.
 * - Al entrar (RealtimeRoot), si fue ESTA persona quien los activó aquí y el permiso sigue
 *   concedido, se vuelven a registrar solos (tras cerrar sesión, si el servidor borró la
 *   suscripción o si cambió la clave VAPID). Otra persona en el mismo navegador los activa ella.
 */

export type PushConfig = {
    enabled: boolean;
    public_key: string | null;
    /** Hash SHA-256 (hex) de los endpoints ya guardados para ti. */
    subscriptions: string[];
};

export type PushStatus =
    | 'loading'
    | 'unsupported'
    | 'unavailable'
    | 'denied'
    | 'enabled'
    | 'disabled';

export const PUSH_OWNER_KEY = 'audax.push.owner';
const REGISTRATION_TIMEOUT_MS = 4_000;

export function pushSupported(): boolean {
    return (
        typeof window !== 'undefined' &&
        window.isSecureContext &&
        'serviceWorker' in navigator &&
        'PushManager' in window &&
        'Notification' in window
    );
}

/** Clave VAPID en base64url → bytes (applicationServerKey). */
export function base64UrlToBytes(value: string): Uint8Array<ArrayBuffer> {
    const padded = value.replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(padded + '='.repeat((4 - (padded.length % 4)) % 4));
    const bytes = new Uint8Array(raw.length);

    for (let index = 0; index < raw.length; index++) {
        bytes[index] = raw.charCodeAt(index);
    }

    return bytes;
}

function sameKey(current: ArrayBuffer | null, expected: Uint8Array): boolean {
    if (current === null) {
        return false;
    }

    const bytes = new Uint8Array(current);

    return (
        bytes.length === expected.length &&
        bytes.every((value, index) => value === expected[index])
    );
}

/** SHA-256 en hexadecimal, como PushSubscription::hashEndpoint() en el servidor. */
export async function endpointHash(endpoint: string): Promise<string> {
    const digest = await crypto.subtle.digest(
        'SHA-256',
        new TextEncoder().encode(endpoint),
    );

    return Array.from(new Uint8Array(digest), (byte) =>
        byte.toString(16).padStart(2, '0'),
    ).join('');
}

async function registration(): Promise<ServiceWorkerRegistration | null> {
    const existing = await navigator.serviceWorker.getRegistration();

    if (existing) {
        return existing;
    }

    // En producción el service worker se registra al cargar (register-sw.ts); en desarrollo no hay.
    return Promise.race([
        navigator.serviceWorker.ready,
        new Promise<null>((resolve) =>
            setTimeout(() => resolve(null), REGISTRATION_TIMEOUT_MS),
        ),
    ]);
}

function owner(): number | null {
    try {
        const value = Number(localStorage.getItem(PUSH_OWNER_KEY));

        return Number.isInteger(value) && value > 0 ? value : null;
    } catch {
        return null;
    }
}

function rememberOwner(userId: number): void {
    try {
        localStorage.setItem(PUSH_OWNER_KEY, String(userId));
    } catch {
        // Sin almacenamiento: no se reactivarán solos al volver a entrar.
    }
}

function forgetOwner(): void {
    try {
        localStorage.removeItem(PUSH_OWNER_KEY);
    } catch {
        // Nada que borrar.
    }
}

function contentEncoding(): 'aes128gcm' | 'aesgcm' {
    const supported = (
        PushManager as unknown as { supportedContentEncodings?: string[] }
    ).supportedContentEncodings;

    return !supported || supported.includes('aes128gcm')
        ? 'aes128gcm'
        : 'aesgcm';
}

async function save(subscription: PushSubscription): Promise<void> {
    const json = subscription.toJSON();

    await realtimeRequest(pushSubscribeRoute.url(), {
        method: 'POST',
        body: {
            endpoint: json.endpoint,
            keys: json.keys,
            content_encoding: contentEncoding(),
        },
    });
}

async function subscribeWith(
    registrationObject: ServiceWorkerRegistration,
    publicKey: string,
): Promise<PushSubscription> {
    const key = base64UrlToBytes(publicKey);
    let subscription = await registrationObject.pushManager.getSubscription();

    // Suscrita con otra clave VAPID (se cambió en el servidor): hay que renovarla.
    if (
        subscription &&
        !sameKey(subscription.options.applicationServerKey, key)
    ) {
        await subscription.unsubscribe();
        subscription = null;
    }

    return (
        subscription ??
        registrationObject.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: key,
        })
    );
}

export async function loadPushConfig(): Promise<PushConfig | null> {
    return realtimeRequest<PushConfig>(pushShowRoute.url());
}

/** Estado de los avisos en este navegador para esta persona. */
export async function pushStatus(config: PushConfig): Promise<PushStatus> {
    if (!pushSupported()) {
        return 'unsupported';
    }

    if (!config.enabled || !config.public_key) {
        return 'unavailable';
    }

    if (Notification.permission === 'denied') {
        return 'denied';
    }

    const registrationObject = await registration();

    if (!registrationObject) {
        return 'unavailable';
    }

    const subscription = await registrationObject.pushManager.getSubscription();

    if (!subscription || Notification.permission !== 'granted') {
        return 'disabled';
    }

    return config.subscriptions.includes(
        await endpointHash(subscription.endpoint),
    )
        ? 'enabled'
        : 'disabled';
}

export async function enablePush(
    config: PushConfig,
    userId: number,
): Promise<'enabled' | 'denied'> {
    if (!config.public_key) {
        throw new Error('Web Push no está configurado en el servidor');
    }

    const permission =
        Notification.permission === 'granted'
            ? 'granted'
            : await Notification.requestPermission();

    if (permission !== 'granted') {
        return 'denied';
    }

    const registrationObject = await registration();

    if (!registrationObject) {
        throw new Error('No hay service worker');
    }

    await save(await subscribeWith(registrationObject, config.public_key));
    rememberOwner(userId);

    return 'enabled';
}

export async function disablePush(): Promise<void> {
    const registrationObject = await registration();
    const subscription = registrationObject
        ? await registrationObject.pushManager.getSubscription()
        : null;

    if (subscription) {
        await realtimeRequest(pushUnsubscribeRoute.url(), {
            method: 'DELETE',
            body: { endpoint: subscription.endpoint },
        });
        await subscription.unsubscribe().catch(() => false);
    }

    forgetOwner();
}

/** Reactivación silenciosa al entrar (ver la cabecera). Nunca pide permiso. */
export async function syncPushSubscription(userId: number): Promise<void> {
    if (
        !pushSupported() ||
        Notification.permission !== 'granted' ||
        owner() !== userId
    ) {
        return;
    }

    const config = await loadPushConfig();

    if (!config?.enabled || !config.public_key) {
        return;
    }

    const registrationObject = await registration();

    if (!registrationObject) {
        return;
    }

    const current = await registrationObject.pushManager.getSubscription();

    if (
        current &&
        sameKey(
            current.options.applicationServerKey,
            base64UrlToBytes(config.public_key),
        ) &&
        config.subscriptions.includes(await endpointHash(current.endpoint))
    ) {
        return;
    }

    await save(await subscribeWith(registrationObject, config.public_key));
}
