import { Head } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    AtSign,
    CalendarClock,
    CalendarOff,
    Clock,
    Gauge,
    ListChecks,
    Milestone,
    Timer,
} from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import type { Phase } from '@/components/empty-state';
import { KeywordText } from '@/components/keyword-text';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { firstName, useRequiredUser } from '@/hooks/use-auth';
import { t } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { home } from '@/routes';

type PanelCard = {
    id: string;
    icon: LucideIcon;
    title: TranslationKey;
    description: TranslationKey;
    empty: TranslationKey;
    phase: Phase;
    wide?: boolean;
};

/** Panel personal "Inicio" (SPEC §5.1). En la Fase 0 cada tarjeta muestra cuándo llega. */
const PANEL: PanelCard[] = [
    {
        id: 'today-tasks',
        icon: ListChecks,
        title: 'home.cards.tasks.title',
        description: 'home.cards.tasks.description',
        empty: 'home.cards.tasks.empty',
        phase: 1,
        wide: true,
    },
    {
        id: 'timer',
        icon: Timer,
        title: 'home.cards.timer.title',
        description: 'home.cards.timer.description',
        empty: 'home.cards.timer.empty',
        phase: 1,
    },
    {
        id: 'week-hours',
        icon: Clock,
        title: 'home.cards.hours.title',
        description: 'home.cards.hours.description',
        empty: 'home.cards.hours.empty',
        phase: 1,
    },
    {
        id: 'workload',
        icon: CalendarClock,
        title: 'home.cards.workload.title',
        description: 'home.cards.workload.description',
        empty: 'home.cards.workload.empty',
        phase: 3,
    },
    {
        id: 'indicators',
        icon: Gauge,
        title: 'home.cards.indicators.title',
        description: 'home.cards.indicators.description',
        empty: 'home.cards.indicators.empty',
        phase: 2,
    },
    {
        id: 'milestones',
        icon: Milestone,
        title: 'home.cards.milestones.title',
        description: 'home.cards.milestones.description',
        empty: 'home.cards.milestones.empty',
        phase: 4,
    },
    {
        id: 'mentions',
        icon: AtSign,
        title: 'home.cards.mentions.title',
        description: 'home.cards.mentions.description',
        empty: 'home.cards.mentions.empty',
        phase: 6,
    },
    {
        id: 'absences',
        icon: CalendarOff,
        title: 'home.cards.absences.title',
        description: 'home.cards.absences.description',
        empty: 'home.cards.absences.empty',
        phase: 3,
    },
];

export default function Home() {
    const user = useRequiredUser();

    return (
        <>
            <Head title={t('home.title')} />

            <div className="flex flex-1 flex-col gap-8 p-4 md:p-6">
                <header className="space-y-1">
                    <h1 className="text-3xl font-normal tracking-tight text-foreground">
                        <KeywordText
                            text={t('home.greeting', { name: firstName(user) })}
                        />
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('home.subtitle')}
                    </p>
                </header>

                <section
                    aria-label={t('home.panel_label')}
                    className="grid gap-4 md:grid-cols-2 xl:grid-cols-4"
                >
                    {PANEL.map((card) => (
                        <Card
                            key={card.id}
                            className={
                                card.wide ? 'gap-4 md:col-span-2' : 'gap-4'
                            }
                            data-test={`home-card-${card.id}`}
                        >
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <card.icon
                                        aria-hidden="true"
                                        className="size-4 text-muted-foreground"
                                        strokeWidth={1.5}
                                    />
                                    <h2>{t(card.title)}</h2>
                                </CardTitle>
                                <CardDescription>
                                    {t(card.description)}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="flex flex-1 flex-col">
                                <EmptyState
                                    className="flex-1"
                                    title={t(card.empty)}
                                    phase={card.phase}
                                />
                            </CardContent>
                        </Card>
                    ))}
                </section>
            </div>
        </>
    );
}

Home.layout = {
    breadcrumbs: [{ title: t('nav.home'), href: home() }],
};
