import { Link, usePage } from '@inertiajs/react';
import { ArrowRight, ShieldCheck } from 'lucide-react';
import { useId, useLayoutEffect, useRef } from 'react';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import { show } from '@/routes/privacy';

/**
 * Aviso de privacidad pendiente (D-075): mientras la persona no haya leído la versión vigente del
 * texto informativo (prop compartida privacy.needs_acknowledgement), un aviso en todas las páginas
 * internas lleva a /privacidad, donde lo lee y lo acepta. En /privacidad no se muestra. Los
 * clientes del portal no tienen la prop: nunca lo ven. Va en el flujo de la página (no flota ni
 * tapa nada) y a 375 px apila el texto y el botón.
 */
export function PrivacyNoticeBanner() {
    const { props, url } = usePage();
    const id = useId();
    const ref = useRef<HTMLElement>(null);
    const target = show.url();
    const visible =
        props.privacy?.needs_acknowledgement === true &&
        url.split(/[?#]/)[0] !== target;

    // Espacio que ocupa el aviso (con su margen), para las páginas de altura fija como el chat:
    // restan --privacy-banner-space y el aviso no empuja su editor fuera de la pantalla.
    useLayoutEffect(() => {
        const element = ref.current;
        const root = document.documentElement;

        if (!visible || element === null) {
            return;
        }

        const measure = () => {
            const margin = Number.parseFloat(
                getComputedStyle(element).marginTop || '0',
            );
            root.style.setProperty(
                '--privacy-banner-space',
                `${element.offsetHeight + (Number.isNaN(margin) ? 0 : margin)}px`,
            );
        };

        measure();
        const observer =
            typeof ResizeObserver === 'undefined'
                ? null
                : new ResizeObserver(measure);
        observer?.observe(element);

        return () => {
            observer?.disconnect();
            root.style.removeProperty('--privacy-banner-space');
        };
    }, [visible]);

    if (!visible) {
        return null;
    }

    return (
        <section
            ref={ref}
            aria-labelledby={`${id}-title`}
            data-test="privacy-notice-banner"
            className="mx-4 mt-4 flex flex-col gap-3 rounded-md border border-border bg-info-soft px-4 py-3 text-sm text-foreground sm:flex-row sm:items-center md:mx-6"
        >
            <ShieldCheck
                aria-hidden="true"
                className="hidden size-5 shrink-0 text-info sm:block"
                strokeWidth={1.5}
            />
            <div className="min-w-0 flex-1 space-y-0.5">
                <p id={`${id}-title`} className="font-medium">
                    {t('privacy.banner.title')}
                </p>
                <p className="text-muted-foreground">
                    {t('privacy.banner.description')}
                </p>
            </div>
            <Button asChild size="sm" className="self-start sm:self-center">
                <Link href={target}>
                    {t('privacy.banner.action')}
                    <ArrowRight aria-hidden="true" />
                </Link>
            </Button>
        </section>
    );
}
