import { usePage } from '@inertiajs/react';
import { Info, TriangleAlert, X } from 'lucide-react';
import { useLayoutEffect, useRef, useState } from 'react';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

const STORAGE_KEY = 'global-banner-dismissed';

function dismissedMessage(): string | null {
    try {
        return window.sessionStorage.getItem(STORAGE_KEY);
    } catch {
        return null;
    }
}

/**
 * Aviso global para toda la plantilla (F-178, ajuste `global_banner` de la consola de WeeklySync):
 * lo escribe un admin en los ajustes y sale arriba en todas las páginas internas, informativo o de
 * advertencia. Se puede ocultar en esta sesión; un mensaje nuevo vuelve a salir. Mide su alto en
 * --global-banner-space para las páginas de altura fija (el chat), como el aviso de privacidad.
 */
export function GlobalBanner() {
    const banner = usePage().props.config?.global_banner ?? null;
    const [dismissed, setDismissed] = useState<string | null>(dismissedMessage);
    const ref = useRef<HTMLElement>(null);
    const visible =
        banner !== null &&
        banner.message.trim() !== '' &&
        dismissed !== banner.message;

    useLayoutEffect(() => {
        const element = ref.current;
        const root = document.documentElement;

        if (!visible || element === null) {
            return;
        }

        const measure = () =>
            root.style.setProperty(
                '--global-banner-space',
                `${element.offsetHeight}px`,
            );

        measure();
        const observer =
            typeof ResizeObserver === 'undefined'
                ? null
                : new ResizeObserver(measure);
        observer?.observe(element);

        return () => {
            observer?.disconnect();
            root.style.removeProperty('--global-banner-space');
        };
    }, [visible]);

    if (!visible || banner === null) {
        return null;
    }

    const warning = banner.tone === 'warning';
    const Icon = warning ? TriangleAlert : Info;

    return (
        <aside
            ref={ref}
            aria-label={t('weeklies.banner.label')}
            className={cn(
                'flex items-start gap-2 border-b px-4 py-2 text-sm text-foreground md:px-6',
                warning ? 'bg-warning-soft' : 'bg-info-soft',
            )}
            data-test="global-banner"
        >
            <Icon
                aria-hidden="true"
                className={cn(
                    'mt-0.5 size-4 shrink-0',
                    warning ? 'text-warning' : 'text-info',
                )}
            />
            <p className="min-w-0 flex-1">{banner.message}</p>
            <button
                type="button"
                aria-label={t('weeklies.banner.dismiss')}
                title={t('weeklies.banner.dismiss')}
                className={cn(
                    'shrink-0 text-muted-foreground hover:text-foreground',
                    FOCUS_RING,
                )}
                onClick={() => {
                    try {
                        window.sessionStorage.setItem(
                            STORAGE_KEY,
                            banner.message,
                        );
                    } catch {
                        // Sin almacenamiento: se oculta solo hasta recargar.
                    }

                    setDismissed(banner.message);
                }}
            >
                <X aria-hidden="true" className="size-4" />
            </button>
        </aside>
    );
}
