/**
 * Registro del service worker de la PWA (public/sw.js).
 * Solo en producción: en desarrollo, Vite sirve los módulos sin hash y un SW cachearía código viejo.
 * La llamada la hace resources/js/app.tsx: `registerServiceWorker();`
 */
export function registerServiceWorker(): void {
    if (
        !import.meta.env.PROD ||
        typeof window === 'undefined' ||
        !('serviceWorker' in navigator)
    ) {
        return;
    }

    const register = () => {
        navigator.serviceWorker
            .register('/sw.js', { scope: '/' })
            .catch((error: unknown) => {
                console.warn(
                    'No se ha podido registrar el service worker.',
                    error,
                );
            });
    };

    // Tras la carga, para no competir con los recursos de la primera pintura.
    if (document.readyState === 'complete') {
        register();
    } else {
        window.addEventListener('load', register, { once: true });
    }
}
