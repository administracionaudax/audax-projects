import { t } from '@/lib/i18n';

/** Enlace "Saltar al contenido" (visible solo con el foco del teclado). */
export function SkipLink({ target = 'contenido' }: { target?: string }) {
    return (
        <a
            href={`#${target}`}
            className="sr-only z-50 rounded-md bg-background px-4 py-2 text-sm text-foreground focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:ring-[3px] focus:ring-ring focus:outline-none"
        >
            {t('nav.skip_to_content')}
        </a>
    );
}
