import { Head, Link, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    ArrowRight,
    Building,
    CalendarDays,
    History,
    LayoutTemplate,
    ListTodo,
    Palette,
    SlidersHorizontal,
    Users,
} from 'lucide-react';
import { PhaseBadge } from '@/components/empty-state';
import type { Phase } from '@/components/empty-state';
import Heading from '@/components/heading';
import {
    Card,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as adminIndex } from '@/routes/admin';
import { index as departmentsIndex } from '@/routes/admin/departments';
import { edit as settingsEdit } from '@/routes/admin/settings';
import { index as statusesIndex } from '@/routes/admin/statuses';
import { index as taskTypesIndex } from '@/routes/admin/task-types';
import { index as usersIndex } from '@/routes/admin/users';
import type { Abilities } from '@/types';

type AreaLink = {
    label: TranslationKey;
    href: string;
    ability: keyof Pick<Abilities, 'manageUsers' | 'manageSettings'>;
};

type AdminArea = {
    id: string;
    icon: LucideIcon;
    title: TranslationKey;
    description: TranslationKey;
    phase: Phase;
    /** Áreas ya disponibles (Fase 1): enlaces a sus páginas. */
    links?: AreaLink[];
};

/** Administración (SPEC §14): áreas que se van activando en cada fase. */
const AREAS: AdminArea[] = [
    {
        id: 'users',
        icon: Users,
        title: 'admin.areas.users.title',
        description: 'admin.areas.users.description',
        phase: 1,
        links: [
            {
                label: 'admin.home.open_users',
                href: usersIndex.url(),
                ability: 'manageUsers',
            },
        ],
    },
    {
        id: 'departments',
        icon: Building,
        title: 'admin.areas.departments.title',
        description: 'admin.areas.departments.description',
        phase: 1,
        links: [
            {
                label: 'admin.home.open_departments',
                href: departmentsIndex.url(),
                ability: 'manageSettings',
            },
        ],
    },
    {
        id: 'task-types',
        icon: ListTodo,
        title: 'admin.areas.task_types.title',
        description: 'admin.areas.task_types.description',
        phase: 1,
        links: [
            {
                label: 'admin.home.open_task_types',
                href: taskTypesIndex.url(),
                ability: 'manageSettings',
            },
            {
                label: 'admin.home.open_statuses',
                href: statusesIndex.url(),
                ability: 'manageSettings',
            },
        ],
    },
    {
        id: 'settings',
        icon: SlidersHorizontal,
        title: 'admin.areas.settings.title',
        description: 'admin.areas.settings.description',
        phase: 1,
        links: [
            {
                label: 'admin.home.open_settings',
                href: settingsEdit.url(),
                ability: 'manageSettings',
            },
        ],
    },
    {
        id: 'holidays',
        icon: CalendarDays,
        title: 'admin.areas.holidays.title',
        description: 'admin.areas.holidays.description',
        phase: 3,
    },
    {
        id: 'templates',
        icon: LayoutTemplate,
        title: 'admin.areas.templates.title',
        description: 'admin.areas.templates.description',
        phase: 4,
    },
    {
        id: 'identity',
        icon: Palette,
        title: 'admin.areas.identity.title',
        description: 'admin.areas.identity.description',
        phase: 5,
    },
    {
        id: 'audit',
        icon: History,
        title: 'admin.areas.audit.title',
        description: 'admin.areas.audit.description',
        phase: 7,
    },
];

export default function AdminIndex() {
    const can = usePage().props.auth?.can;

    return (
        <>
            <Head title={t('admin.title')} />

            <div className="flex flex-1 flex-col p-4 md:p-6">
                <Heading
                    as="h1"
                    title={t('admin.heading')}
                    description={t('admin.description')}
                />

                <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {AREAS.map((area) => {
                        const links = (area.links ?? []).filter(
                            (link) => can?.[link.ability] === true,
                        );

                        return (
                            <li key={area.id} className="flex">
                                <Card
                                    className="flex-1 gap-4"
                                    data-test={`admin-area-${area.id}`}
                                >
                                    <CardHeader>
                                        <area.icon
                                            aria-hidden="true"
                                            className="mb-2 size-5 text-muted-foreground"
                                            strokeWidth={1.5}
                                        />
                                        <CardTitle className="text-base">
                                            <h2>{t(area.title)}</h2>
                                        </CardTitle>
                                        <CardDescription>
                                            {t(area.description)}
                                        </CardDescription>
                                    </CardHeader>
                                    <CardFooter className="mt-auto flex flex-wrap gap-x-4 gap-y-2">
                                        {area.links === undefined ? (
                                            <PhaseBadge phase={area.phase} />
                                        ) : (
                                            links.map((link) => (
                                                <Link
                                                    key={link.href}
                                                    href={link.href}
                                                    className={cn(
                                                        'inline-flex items-center gap-1 rounded-sm text-sm font-medium text-primary-text hover:underline',
                                                        FOCUS_RING,
                                                    )}
                                                >
                                                    {t(link.label)}
                                                    <ArrowRight
                                                        aria-hidden="true"
                                                        className="size-4"
                                                    />
                                                </Link>
                                            ))
                                        )}
                                    </CardFooter>
                                </Card>
                            </li>
                        );
                    })}
                </ul>
            </div>
        </>
    );
}

AdminIndex.layout = {
    breadcrumbs: [{ title: t('nav.admin'), href: adminIndex() }],
};
