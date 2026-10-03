import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * Logotipo oficial de Audax Studio (SVG de audaxstudio.com, public/brand/audax-logo.svg).
 * Se pinta con currentColor, así que el tono lo decide el texto:
 * - tone="auto": negro oficial (#1D1D1B) en tema claro y blanco en oscuro.
 * - tone="inverse": siempre blanco (sobre el degradado de marca).
 * El alto se controla con className (h-*); el ancho es proporcional (500 × 83).
 */
const LOGO_PATH =
    'M47.4054 82.8776H0L23.7633 41.3776L47.4054 0L71.1688 41.5L94.8109 82.8776H47.4054ZM391.246 82.8776L367.604 41.5L343.841 0L320.078 41.5L296.314 83H391.246V82.8776ZM291.707 41.5C291.707 18.6077 273.278 0 250.606 0H209.505V83H250.606C273.278 83 291.707 64.3923 291.707 41.5ZM144.399 82.8776C167.071 82.8776 185.499 64.3923 185.499 41.3776V0H103.298V41.5C103.298 64.3923 121.726 82.8776 144.399 82.8776C144.399 83 144.399 83 144.399 82.8776ZM500 0H464.234L452.595 20.444L440.955 0H405.189L428.831 41.5L405.189 82.8776H440.955L452.595 62.4336L464.234 82.8776H500L476.237 41.3776L500 0Z';

/** Triángulo de la «A» (isotipo), para espacios cuadrados pequeños. */
const ISOTYPE_PATH =
    'M100 176H0L50.1279 87.87L100 0L150.128 88.13L200 176H100Z';

export function AudaxWordmark({
    tone = 'auto',
    className,
}: {
    tone?: 'auto' | 'inverse';
    className?: string;
}) {
    return (
        <svg
            role="img"
            aria-label={t('brand.wordmark')}
            viewBox="0 0 500 83"
            className={cn(
                'h-4 w-auto shrink-0 select-none',
                tone === 'inverse'
                    ? 'text-white'
                    : 'text-logo-ink dark:text-white',
                className,
            )}
        >
            <path d={LOGO_PATH} fill="currentColor" />
        </svg>
    );
}

export function AudaxIsotype({ className }: { className?: string }) {
    return (
        <svg
            aria-hidden="true"
            viewBox="0 0 200 176"
            className={cn('h-4 w-auto shrink-0', className)}
        >
            <path d={ISOTYPE_PATH} fill="currentColor" />
        </svg>
    );
}

/** Logotipo de la barra lateral: logotipo AUDAX + nombre corto de la app. */
export default function AppLogo() {
    return (
        <>
            {/* Isotipo visible solo con la barra lateral contraída. */}
            <span
                aria-hidden="true"
                className="hidden size-8 shrink-0 items-center justify-center rounded-md bg-brand-navy text-white group-data-[collapsible=icon]:flex dark:bg-white dark:text-brand-navy"
            >
                <AudaxIsotype className="h-3.5" />
            </span>
            <span className="grid flex-1 gap-1 text-left leading-tight group-data-[collapsible=icon]:hidden">
                <AudaxWordmark className="h-3.5" />
                <span className="truncate text-xs text-muted-foreground">
                    {t('brand.app_short_name')}
                </span>
            </span>
        </>
    );
}
