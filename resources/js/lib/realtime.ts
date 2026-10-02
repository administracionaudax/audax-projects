import { configureEcho } from '@laravel/echo-react';

/**
 * Conexión de Echo con Reverb (Fase 6, D-068). Llega en la prop compartida `realtime` porque el
 * frontend se compila en el Mac: el host no puede ir en variables VITE_.
 */
export interface RealtimeConfig {
    key: string;
    host: string;
    port: number;
    scheme: 'http' | 'https';
}

let configuredKey: string | null = null;

/** ¿Hay tiempo real en esta sesión? Si no, la interfaz usa consultas periódicas. */
export function realtimeEnabled(): boolean {
    return configuredKey !== null;
}

/**
 * Configura Echo una sola vez (idempotente). Con null no hace nada: los hooks de Echo no se
 * deben usar sin comprobar antes realtimeEnabled().
 */
export function configureRealtime(config: RealtimeConfig | null): void {
    if (
        config === null ||
        typeof window === 'undefined' ||
        configuredKey === config.key
    ) {
        return;
    }

    const tls = config.scheme === 'https';

    configureEcho({
        broadcaster: 'reverb',
        key: config.key,
        wsHost: config.host,
        wsPort: tls ? 80 : config.port,
        wssPort: tls ? config.port : 443,
        forceTLS: tls,
        enabledTransports: tls ? ['wss'] : ['ws'],
    });

    configuredKey = config.key;
}
