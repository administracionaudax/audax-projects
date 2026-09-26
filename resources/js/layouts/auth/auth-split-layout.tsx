import { AudaxWordmark } from '@/components/app-logo';
import { KeywordText } from '@/components/keyword-text';
import { ThemeSync } from '@/components/theme-sync';
import { t } from '@/lib/i18n';
import type { AuthLayoutProps } from '@/types';

/**
 * Layout de autenticación (SPEC §3.1): panel con el degradado de marca y formulario sobre el fondo.
 * En móvil el degradado queda como cabecera compacta.
 * El panel usa los tokens del tema oscuro (clase `dark`). Sobre el degradado (que lleva su velo
 * navy, ver --brand-veil en app.css) el texto secundario va en `text-on-gradient-muted` (blanco
 * al 85 %) y la palabra clave en `text-on-gradient-keyword`: ambos cumplen AA en toda la
 * superficie, también en la franja inferior clara (tests/js/brand-gradient-contrast.test.ts).
 */
export default function AuthSplitLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="flex min-h-dvh flex-col bg-background lg:grid lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
            <ThemeSync />
            <aside className="dark relative flex shrink-0 flex-col justify-between gap-6 px-6 py-6 text-foreground bg-brand-gradient sm:px-10 lg:min-h-dvh lg:py-10">
                <AudaxWordmark tone="inverse" className="h-5 lg:h-6" />

                <div className="space-y-3 lg:space-y-5">
                    <p className="max-w-md text-2xl leading-tight text-balance lg:text-4xl">
                        <KeywordText
                            text={t('brand.tagline')}
                            keywordClassName="text-on-gradient-keyword"
                        />
                    </p>
                    <p className="hidden max-w-md text-base text-on-gradient-muted lg:block">
                        {t('brand.tagline_description')}
                    </p>
                </div>

                <p className="hidden text-sm text-on-gradient-muted lg:block">
                    {t('brand.company')}
                </p>
            </aside>

            <main
                id="contenido"
                className="flex flex-1 items-start justify-center px-6 py-10 sm:px-10 lg:items-center"
            >
                <div className="flex w-full max-w-sm flex-col gap-8">
                    {(title || description) && (
                        <div className="space-y-2">
                            {title && (
                                <h1 className="text-2xl font-normal text-foreground">
                                    {title}
                                </h1>
                            )}
                            {description && (
                                <p className="text-sm text-balance text-muted-foreground">
                                    {description}
                                </p>
                            )}
                        </div>
                    )}
                    {children}
                </div>
            </main>
        </div>
    );
}
