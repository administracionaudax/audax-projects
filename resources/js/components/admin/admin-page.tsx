import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { KeywordText } from '@/components/keyword-text';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as departmentsIndex } from '@/routes/admin/departments';
import { edit as settingsEdit } from '@/routes/admin/settings';
import { index as statusesIndex } from '@/routes/admin/statuses';
import { index as taskTypesIndex } from '@/routes/admin/task-types';
import { index as usersIndex } from '@/routes/admin/users';
import type { Abilities } from '@/types';

export type AdminSection =
    | 'users'
    | 'departments'
    | 'task-types'
    | 'statuses'
    | 'settings';

type SectionLink = {
    id: AdminSection;
    label: TranslationKey;
    href: string;
    ability: keyof Pick<Abilities, 'manageUsers' | 'manageSettings'>;
};

const SECTIONS: SectionLink[] = [
    {
        id: 'users',
        label: 'admin.nav.users',
        href: usersIndex.url(),
        ability: 'manageUsers',
    },
    {
        id: 'departments',
        label: 'admin.nav.departments',
        href: departmentsIndex.url(),
        ability: 'manageSettings',
    },
    {
        id: 'task-types',
        label: 'admin.nav.task_types',
        href: taskTypesIndex.url(),
        ability: 'manageSettings',
    },
    {
        id: 'statuses',
        label: 'admin.nav.statuses',
        href: statusesIndex.url(),
        ability: 'manageSettings',
    },
    {
        id: 'settings',
        label: 'admin.nav.settings',
        href: settingsEdit.url(),
        ability: 'manageSettings',
    },
];

/** Secciones de la administración de la Fase 1 que puede abrir quien tenga ese permiso. */
export function adminSections(
    can: Partial<Abilities> | undefined,
): SectionLink[] {
    return SECTIONS.filter((section) => can?.[section.ability] === true);
}

/**
 * Marco de las páginas de administración: título (un solo h1), descripción, acciones y la
 * navegación entre secciones (pestañas con aria-current). Se desplaza en horizontal en el móvil.
 */
export function AdminPage({
    section,
    title,
    description,
    actions,
    children,
}: {
    section: AdminSection;
    /** Admite palabras clave con [[…]]. */
    title: string;
    description?: string;
    actions?: ReactNode;
    children: ReactNode;
}) {
    const can = usePage().props.auth?.can;
    const sections = adminSections(can);

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

            {sections.length > 1 ? (
                <nav
                    aria-label={t('admin.nav.label')}
                    className="-mx-4 overflow-x-auto border-b px-4 md:mx-0 md:px-0"
                >
                    <ul className="flex min-w-max gap-1">
                        {sections.map((item) => {
                            const current = item.id === section;

                            return (
                                <li key={item.id}>
                                    <Link
                                        href={item.href}
                                        aria-current={
                                            current ? 'page' : undefined
                                        }
                                        className={cn(
                                            '-mb-px flex border-b-2 px-3 py-2 text-sm',
                                            current
                                                ? 'border-primary font-medium text-foreground'
                                                : 'border-transparent text-muted-foreground hover:text-foreground',
                                            FOCUS_RING,
                                        )}
                                    >
                                        {t(item.label)}
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </nav>
            ) : null}

            <div className="min-w-0">{children}</div>
        </div>
    );
}
