import { Head, Link } from '@inertiajs/react';
import { Bug } from 'lucide-react';
import Heading from '@/components/heading';
import { HelpFaqTab } from '@/components/help/help-faq';
import { HelpGeneral } from '@/components/help/help-general';
import { HelpTutorials } from '@/components/help/help-tutorials';
import { useHelpLive } from '@/components/help/use-help-live';
import { SuggestionsTab } from '@/components/suggestions/suggestions-tab';
import { Button } from '@/components/ui/button';
import { WeeklyTabs } from '@/components/weeklies/weekly-tabs';
import { t } from '@/lib/i18n';
import { index as helpIndex } from '@/routes/help';
import type { HelpPageProps, HelpTab } from '@/types/weeklies';

/** Las props de cada pestaña, para recargar solo las suyas (tiempo real, F-170). */
const TAB_PROPS: Record<HelpTab, string[]> = {
    general: ['updates', 'releases', 'settings'],
    tutoriales: ['tutorials', 'releases'],
    preguntas: ['faq_sections'],
    sugerencias: ['suggestions'],
};

/**
 * /ayuda: centro de ayuda (F-148 a F-158) con las pestañas General, Tutoriales, Preguntas frecuentes
 * y Sugerencias (F-159 a F-170) en la URL (?pestana=), y «Reportar un bug» (F-149), que abre una
 * sugerencia en la categoría Bugs.
 */
export default function Help({
    tab,
    can,
    settings,
    updates,
    releases,
    tutorials,
    faq_sections,
    suggestions,
    upload,
}: HelpPageProps) {
    useHelpLive(tab === 'sugerencias' ? 'suggestions' : 'help', TAB_PROPS[tab]);

    const tabs = [
        { id: 'general', label: t('help_center.tabs.general') },
        { id: 'tutoriales', label: t('help_center.tabs.tutorials') },
        { id: 'preguntas', label: t('help_center.tabs.faq') },
        ...(can.suggestions
            ? [{ id: 'sugerencias', label: t('help_center.tabs.suggestions') }]
            : []),
    ].map((item) => ({
        ...item,
        href: helpIndex.url({
            query: item.id === 'general' ? {} : { pestana: item.id },
        }),
    }));

    return (
        <>
            <Head title={t('help_center.title')} />
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        as="h1"
                        title={t('help_center.heading')}
                        description={t('help_center.description')}
                    />
                    {can.suggestions ? (
                        <Button variant="secondary" asChild>
                            <Link
                                href={helpIndex.url({
                                    query: {
                                        pestana: 'sugerencias',
                                        vista: 'feedback',
                                        nueva: 'bug',
                                    },
                                })}
                                data-test="help-report-bug"
                            >
                                <Bug aria-hidden="true" />
                                {t('help.report_bug')}
                            </Link>
                        </Button>
                    ) : null}
                </div>
                <WeeklyTabs
                    label={t('help.tabs_label')}
                    tabs={tabs}
                    current={tab}
                />
                {tab === 'general' && updates ? (
                    <HelpGeneral
                        updates={updates}
                        settings={settings}
                        releases={releases}
                        manage={can.manage}
                        attachmentMaxMb={upload.attachment_max_mb}
                    />
                ) : null}
                {tab === 'tutoriales' && tutorials ? (
                    <HelpTutorials
                        tutorials={tutorials}
                        releases={releases ?? []}
                        manage={can.manage}
                        maxBytes={upload.video_max_bytes}
                    />
                ) : null}
                {tab === 'preguntas' && faq_sections ? (
                    <HelpFaqTab sections={faq_sections} manage={can.manage} />
                ) : null}
                {tab === 'sugerencias' && suggestions ? (
                    <SuggestionsTab
                        data={suggestions}
                        attachmentMaxMb={upload.attachment_max_mb}
                    />
                ) : null}
            </div>
        </>
    );
}
