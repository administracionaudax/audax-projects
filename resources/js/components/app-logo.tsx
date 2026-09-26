import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * Logotipo provisional de texto (SPEC §3.1): "AUDAX" en DM Sans con tracking amplio.
 * Se sustituirá por el SVG oficial (versiones clara y oscura) cuando llegue.
 * - tone="auto": navy en tema claro y blanco en oscuro.
 * - tone="inverse": siempre blanco (sobre el degradado de marca).
 */
export function AudaxWordmark({
    tone = 'auto',
    className,
}: {
    tone?: 'auto' | 'inverse';
    className?: string;
}) {
    return (
        <span
            className={cn(
                'font-medium tracking-[0.32em] uppercase select-none',
                tone === 'inverse'
                    ? 'text-white'
                    : 'text-brand-navy dark:text-white',
                className,
            )}
        >
            {t('brand.wordmark')}
        </span>
    );
}

/** Logotipo de la barra lateral: palabra "AUDAX" + nombre corto de la app. */
export default function AppLogo() {
    return (
        <>
            {/* Monograma visible solo con la barra lateral contraída. */}
            <span
                aria-hidden="true"
                className="hidden size-8 shrink-0 items-center justify-center rounded-md bg-brand-navy text-sm font-medium text-white group-data-[collapsible=icon]:flex dark:bg-white dark:text-brand-navy"
            >
                {t('brand.monogram')}
            </span>
            <span className="grid flex-1 text-left leading-tight group-data-[collapsible=icon]:hidden">
                <AudaxWordmark className="text-base" />
                <span className="truncate text-xs text-muted-foreground">
                    {t('brand.app_short_name')}
                </span>
            </span>
        </>
    );
}
