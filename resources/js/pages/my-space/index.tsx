import { Head, Link } from '@inertiajs/react';
import { ListChecks } from 'lucide-react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { MyWeeklyEditor } from '@/components/weeklies/my-weekly-editor';
import { MyWeeksList } from '@/components/weeklies/my-weeks-list';
import { WeeklyPlaceholder } from '@/components/weeklies/weekly-placeholder';
import { WeeklyTabs } from '@/components/weeklies/weekly-tabs';
import { StreakValue } from '@/components/weeklies/weekly-ui';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { index as mySpaceIndex } from '@/routes/my-space';
import type { MySpacePageProps } from '@/types/weeklies';

/**
 * /mi-espacio (F-041 a F-054; MySpace de WeeklySync): pestañas «Reportes» y «Tareas».
 * - Reportes: «Mis envíos» (F-042) con mi racha; con ?semana={id}, «Mi weekly» de esa semana
 *   (F-043 a F-054), en solo lectura si está cerrada.
 * - Tareas: llega en la 10.6 (de momento, el enlace a Mis tareas).
 */
export default function MySpace({
    tab,
    weeks,
    streak,
    editor,
}: MySpacePageProps) {
    const tabs = [
        {
            id: 'reportes',
            label: t('my_space.tabs.reports'),
            href: mySpaceIndex.url(),
        },
        {
            id: 'tareas',
            label: t('my_space.tabs.tasks'),
            href: mySpaceIndex.url({ query: { pestana: 'tareas' } }),
        },
    ];

    return (
        <>
            <Head
                title={
                    editor
                        ? `${t('weeklies.editor.title')} · ${editor.cycle.number}`
                        : t('my_space.title')
                }
            />
            <div className="mx-auto flex w-full max-w-4xl min-w-0 flex-col gap-6 p-4 md:p-6">
                <Heading
                    as="h1"
                    title={t('my_space.heading')}
                    description={t('my_space.description')}
                />
                <WeeklyTabs
                    label={t('my_space.tabs.label')}
                    tabs={tabs}
                    current={tab}
                />
                {tab === 'tareas' ? (
                    <div className="grid gap-4">
                        <WeeklyPlaceholder delivery="10.6" />
                        <Button
                            asChild
                            variant="outline"
                            className="justify-self-start"
                        >
                            <Link href={urls.myTasks()}>
                                <ListChecks aria-hidden="true" />
                                {t('my_space.tasks_link')}
                            </Link>
                        </Button>
                    </div>
                ) : editor ? (
                    <MyWeeklyEditor
                        // Al cambiar de semana, al renunciar a la exención o al cerrarse, de cero.
                        key={`${editor.cycle.id}-${editor.read_only}-${editor.can.write}`}
                        editor={editor}
                    />
                ) : (
                    <section
                        aria-labelledby="my-weeks-title"
                        className="grid gap-3"
                    >
                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                            <h2
                                id="my-weeks-title"
                                className="text-lg font-normal"
                            >
                                {t('weeklies.my_weeks.title')}
                            </h2>
                            <span className="grid justify-items-end text-sm">
                                <StreakValue streak={streak.streak} />
                                <span className="text-xs text-muted-foreground">
                                    {t('weeklies.streak.summary', {
                                        submitted: streak.submitted,
                                        on_time: streak.on_time,
                                    })}
                                </span>
                            </span>
                        </div>
                        <MyWeeksList weeks={weeks} />
                    </section>
                )}
            </div>
        </>
    );
}

MySpace.layout = {
    breadcrumbs: [{ title: t('my_space.title'), href: mySpaceIndex() }],
};
