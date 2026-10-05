import { router, usePage } from '@inertiajs/react';
import { RefreshCw, WifiOff } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import { version as versionRoute } from '@/routes/app';

/** Cada cuánto se pregunta por la versión mientras la pestaña está a la vista. */
export const VERSION_CHECK_MS = 10 * 60_000;

/** Al volver a una pestaña que llevaba oculta al menos esto, se recargan los datos de la página. */
export const STALE_AFTER_HIDDEN_MS = 10 * 60_000;

async function serverVersion(): Promise<string | null> {
    try {
        const response = await fetch(versionRoute.url(), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            cache: 'no-store',
        });

        if (!response.ok) {
            return null;
        }

        return (
            ((await response.json()) as { version?: string | null }).version ??
            null
        );
    } catch {
        return null;
    }
}

/**
 * Que la pestaña abierta no se quede vieja (F-013 y F-014 de WeeklySync):
 * - versión nueva: pregunta a GET /version al volver a la pestaña, al recuperar la conexión y cada
 *   10 minutos; si ha cambiado (despliegue), avisa con «Recargar». Nunca recarga sola: lo que se
 *   está escribiendo no se pierde (y la siguiente navegación de Inertia ya recarga la página),
 * - sin conexión: «Sin conexión», y al volver la conexión se recargan los datos de la página,
 * - pestaña olvidada: al volver tras 10 minutos oculta, se recargan los datos de la página.
 */
export function AppFreshness() {
    const page = usePage();
    const current = page.version ?? null;
    const [outdated, setOutdated] = useState(false);
    const [offline, setOffline] = useState(
        typeof navigator !== 'undefined' && navigator.onLine === false,
    );
    const hiddenAt = useRef<number | null>(null);
    const versionRef = useRef(current);

    useEffect(() => {
        versionRef.current = current;
    }, [current]);

    useEffect(() => {
        if (versionRef.current === null) {
            return;
        }

        const check = async () => {
            const latest = await serverVersion();

            if (
                latest !== null &&
                versionRef.current !== null &&
                latest !== versionRef.current
            ) {
                setOutdated(true);
            }
        };

        const onVisibility = () => {
            if (document.visibilityState === 'hidden') {
                hiddenAt.current = Date.now();

                return;
            }

            const away =
                hiddenAt.current === null ? 0 : Date.now() - hiddenAt.current;
            hiddenAt.current = null;
            void check();

            if (away >= STALE_AFTER_HIDDEN_MS) {
                router.reload();
            }
        };

        const onOnline = () => {
            setOffline(false);
            void check();
            router.reload();
        };

        const onOffline = () => setOffline(true);

        const interval = setInterval(() => {
            if (document.visibilityState === 'visible') {
                void check();
            }
        }, VERSION_CHECK_MS);

        document.addEventListener('visibilitychange', onVisibility);
        window.addEventListener('online', onOnline);
        window.addEventListener('offline', onOffline);

        return () => {
            clearInterval(interval);
            document.removeEventListener('visibilitychange', onVisibility);
            window.removeEventListener('online', onOnline);
            window.removeEventListener('offline', onOffline);
        };
    }, []);

    if (!outdated && !offline) {
        return null;
    }

    return (
        <div
            role="status"
            className="fixed inset-x-4 bottom-4 z-50 flex flex-wrap items-center gap-3 border bg-card p-3 text-sm sm:inset-x-auto sm:right-4 sm:max-w-sm"
            data-test={offline ? 'app-offline' : 'app-outdated'}
        >
            {offline ? (
                <>
                    <WifiOff
                        aria-hidden="true"
                        className="size-4 shrink-0 text-warning"
                    />
                    <p className="min-w-0 flex-1">
                        {t('app_freshness.offline')}
                    </p>
                </>
            ) : (
                <>
                    <RefreshCw
                        aria-hidden="true"
                        className="size-4 shrink-0 text-info"
                    />
                    <p className="min-w-0 flex-1">
                        {t('app_freshness.outdated')}
                    </p>
                    <Button
                        type="button"
                        size="sm"
                        onClick={() => window.location.reload()}
                    >
                        {t('app_freshness.reload')}
                    </Button>
                </>
            )}
        </div>
    );
}
