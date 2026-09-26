import { Head, setLayoutProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    BarChart3,
    Building2,
    CalendarRange,
    Clock,
    FolderKanban,
    ListChecks,
    MessagesSquare,
    Wallet,
} from 'lucide-react';
import { HeroEmptyState } from '@/components/empty-state';
import type { Phase } from '@/components/empty-state';
import { t } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { index as chatIndex } from '@/routes/chat';
import { index as clientsIndex } from '@/routes/clients';
import { index as hourBanksIndex } from '@/routes/hour-banks';
import { index as myTasksIndex } from '@/routes/my-tasks';
import { index as projectsIndex } from '@/routes/projects';
import { index as reportsIndex } from '@/routes/reports';
import { index as timeIndex } from '@/routes/time';
import { index as workloadIndex } from '@/routes/workload';
import type {
    NavItem,
    PlaceholderPageProps,
    PlaceholderSection,
} from '@/types';

type SectionInfo = {
    nav: TranslationKey;
    title: TranslationKey;
    description: TranslationKey;
    phase: Phase;
    icon: LucideIcon;
    href: NavItem['href'];
};

/** Qué llegará a cada sección de la barra lateral y en qué fase (SPEC §17). */
export const SECTIONS: Record<PlaceholderSection, SectionInfo> = {
    'my-tasks': {
        nav: 'nav.my_tasks',
        title: 'placeholder.my_tasks.title',
        description: 'placeholder.my_tasks.description',
        phase: 1,
        icon: ListChecks,
        href: myTasksIndex(),
    },
    projects: {
        nav: 'nav.projects',
        title: 'placeholder.projects.title',
        description: 'placeholder.projects.description',
        phase: 1,
        icon: FolderKanban,
        href: projectsIndex(),
    },
    clients: {
        nav: 'nav.clients',
        title: 'placeholder.clients.title',
        description: 'placeholder.clients.description',
        phase: 1,
        icon: Building2,
        href: clientsIndex(),
    },
    'hour-banks': {
        nav: 'nav.hour_banks',
        title: 'placeholder.hour_banks.title',
        description: 'placeholder.hour_banks.description',
        phase: 1,
        icon: Wallet,
        href: hourBanksIndex(),
    },
    time: {
        nav: 'nav.time',
        title: 'placeholder.time.title',
        description: 'placeholder.time.description',
        phase: 1,
        icon: Clock,
        href: timeIndex(),
    },
    workload: {
        nav: 'nav.workload',
        title: 'placeholder.workload.title',
        description: 'placeholder.workload.description',
        phase: 3,
        icon: CalendarRange,
        href: workloadIndex(),
    },
    reports: {
        nav: 'nav.reports',
        title: 'placeholder.reports.title',
        description: 'placeholder.reports.description',
        phase: 2,
        icon: BarChart3,
        href: reportsIndex(),
    },
    chat: {
        nav: 'nav.chat',
        title: 'placeholder.chat.title',
        description: 'placeholder.chat.description',
        phase: 6,
        icon: MessagesSquare,
        href: chatIndex(),
    },
};

/** Marcador de las secciones que se construyen en las fases 1 a 6. */
export default function Placeholder({ section }: PlaceholderPageProps) {
    const info = SECTIONS[section] ?? SECTIONS['my-tasks'];
    const name = t(info.nav);

    setLayoutProps({ breadcrumbs: [{ title: name, href: info.href }] });

    return (
        <>
            <Head title={name} />

            <div className="flex flex-1 flex-col p-4 md:p-6">
                <HeroEmptyState
                    icon={info.icon}
                    eyebrow={name}
                    title={t(info.title)}
                    description={t(info.description)}
                    phase={info.phase}
                    className="flex-1"
                />
            </div>
        </>
    );
}
