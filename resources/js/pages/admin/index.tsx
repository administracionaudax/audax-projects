import { Head } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
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
import { t } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { index as adminIndex } from '@/routes/admin';

type AdminArea = {
    id: string;
    icon: LucideIcon;
    title: TranslationKey;
    description: TranslationKey;
    phase: Phase;
};

/** Administración (SPEC §14): áreas que se irán activando en cada fase. */
const AREAS: AdminArea[] = [
    {
        id: 'users',
        icon: Users,
        title: 'admin.areas.users.title',
        description: 'admin.areas.users.description',
        phase: 1,
    },
    {
        id: 'departments',
        icon: Building,
        title: 'admin.areas.departments.title',
        description: 'admin.areas.departments.description',
        phase: 1,
    },
    {
        id: 'task-types',
        icon: ListTodo,
        title: 'admin.areas.task_types.title',
        description: 'admin.areas.task_types.description',
        phase: 1,
    },
    {
        id: 'settings',
        icon: SlidersHorizontal,
        title: 'admin.areas.settings.title',
        description: 'admin.areas.settings.description',
        phase: 1,
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
    return (
        <>
            <Head title={t('admin.title')} />

            <div className="flex flex-1 flex-col p-4 md:p-6">
                <Heading
                    title={t('admin.heading')}
                    description={t('admin.description')}
                />

                <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {AREAS.map((area) => (
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
                                <CardFooter className="mt-auto">
                                    <PhaseBadge phase={area.phase} />
                                </CardFooter>
                            </Card>
                        </li>
                    ))}
                </ul>
            </div>
        </>
    );
}

AdminIndex.layout = {
    breadcrumbs: [{ title: t('nav.admin'), href: adminIndex() }],
};
