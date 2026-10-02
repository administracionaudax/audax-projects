import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { KeywordText } from '@/components/keyword-text';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as recurringIndex } from '@/routes/recurring';
import { index as templatesIndex } from '@/routes/templates';

export type TemplatesSection = 'templates' | 'recurring';

/**
 * Marco de /admin/plantillas y /admin/tareas-recurrentes (SPEC §14): un solo h1, descripción,
 * acciones y la navegación entre las dos secciones (pestañas con aria-current), con scroll
 * horizontal propio en el móvil.
 */
export function TemplatesAdminFrame({
    section,
    title,
    description,
    actions,
    children,
}: {
    section: TemplatesSection;
    /** Admite palabras clave con [[…]]. */
    title: string;
    description?: string;
    actions?: ReactNode;
    children: ReactNode;
}) {
    const items: { id: TemplatesSection; label: string; href: string }[] = [
        {
            id: 'templates',
            label: t('templates.nav.templates'),
            href: templatesIndex.url(),
        },
        {
            id: 'recurring',
            label: t('templates.nav.recurring'),
            href: recurringIndex.url(),
        },
    ];

    return (
        <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
            <header className="flex flex-wrap items-start justify-between gap-4">
                <div className="min-w-0 space-y-1">
                    <h1 className="text-2xl font-normal tracking-tight">
                        <KeywordText text={title} />
                    </h1>
                    {description ? (
                        <p className="text-sm text-muted-foreground">
                            {description}
                        </p>
                    ) : null}
                </div>
                {actions ? (
                    <div className="flex flex-wrap gap-2">{actions}</div>
                ) : null}
            </header>

            <nav
                aria-label={t('templates.nav.label')}
                className="-mx-4 overflow-x-auto border-b px-4 md:mx-0 md:px-0"
            >
                <ul className="flex min-w-max gap-1">
                    {items.map((item) => {
                        const current = item.id === section;

                        return (
                            <li key={item.id}>
                                <Link
                                    href={item.href}
                                    aria-current={current ? 'page' : undefined}
                                    className={cn(
                                        '-mb-px flex border-b-2 px-3 py-2 text-sm',
                                        current
                                            ? 'border-primary font-medium text-foreground'
                                            : 'border-transparent text-muted-foreground hover:text-foreground',
                                        FOCUS_RING,
                                    )}
                                >
                                    {item.label}
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            </nav>

            <div className="min-w-0">{children}</div>
        </div>
    );
}
