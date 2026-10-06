import type { Page, PendingVisit } from '@inertiajs/core';
import { router } from '@inertiajs/react';

type DeferredGroups = Record<string, string[]>;

function valueAt(props: Record<string, unknown>, path: string): unknown {
    return path
        .split('.')
        .reduce<unknown>(
            (value, key) =>
                value !== null && typeof value === 'object'
                    ? (value as Record<string, unknown>)[key]
                    : undefined,
            props,
        );
}

/**
 * Props diferidas de la página que aún no han llegado, por grupo: las que la página pidió al cargarse
 * (`initialDeferredProps`) y siguen sin valor, salvo las rescatadas (fallaron en el servidor) y las
 * que ya se están pidiendo.
 */
export function missingDeferredProps(
    page: Pick<
        Page,
        'props' | 'deferredProps' | 'initialDeferredProps' | 'rescuedProps'
    >,
    inFlight: ReadonlySet<string> = new Set(),
): DeferredGroups {
    const groups = page.initialDeferredProps ?? page.deferredProps ?? {};
    const rescued = new Set(page.rescuedProps ?? []);
    const missing: DeferredGroups = {};

    for (const [group, props] of Object.entries(groups)) {
        const pending = props.filter(
            (prop) =>
                valueAt(page.props as Record<string, unknown>, prop) ===
                    undefined &&
                !rescued.has(prop) &&
                !inFlight.has(prop),
        );

        if (pending.length > 0) {
            missing[group] = pending;
        }
    }

    return missing;
}

let installed = false;

/** La página de la primera carga (Inertia la deja en <script data-page="app">). */
function initialPage(): Page | null {
    try {
        const json = document.querySelector(
            'script[data-page="app"]',
        )?.textContent;

        return json ? (JSON.parse(json) as Page) : null;
    } catch {
        return null;
    }
}

/**
 * Red de seguridad para las props diferidas (revisión de formularios, D-310). Inertia cancela su
 * carga si, antes de que lleguen, se hace una visita a otra ruta (p. ej. un POST con `only` desde un
 * diálogo) o se cambia la URL nada más montar la página, y no vuelve a pedirlas: el desplegable que
 * dependía de ellas se quedaba en «Cargando…» para siempre. Al terminar cualquier visita, se vuelven a
 * pedir las que falten.
 */
export function installDeferredPropsGuard(): void {
    if (installed || typeof window === 'undefined') {
        return;
    }

    installed = true;
    const inFlight = new Set<string>();
    let current: Page | null = initialPage();

    const isDeferredLoad = (visit: PendingVisit): boolean =>
        (visit as PendingVisit & { deferredProps?: boolean }).deferredProps ===
        true;

    router.on('navigate', (event) => {
        current = event.detail.page;
    });
    router.on('success', (event) => {
        current = event.detail.page;
    });
    router.on('start', (event) => {
        const visit = event.detail.visit;

        if (isDeferredLoad(visit)) {
            visit.only.forEach((prop) => inFlight.add(prop));
        }
    });
    router.on('finish', (event) => {
        const visit = event.detail.visit;

        if (isDeferredLoad(visit)) {
            visit.only.forEach((prop) => inFlight.delete(prop));

            return;
        }

        if (visit.prefetch) {
            return;
        }

        // Después de que Inertia lance sus propias cargas diferidas de la respuesta.
        window.setTimeout(() => {
            if (current === null) {
                return;
            }

            for (const only of Object.values(
                missingDeferredProps(current, inFlight),
            )) {
                router.reload({
                    only,
                    deferredProps: true,
                    preserveErrors: true,
                } as Parameters<typeof router.reload>[0]);
            }
        }, 50);
    });
}
