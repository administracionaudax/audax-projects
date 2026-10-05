import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { WeeklyHistory } from '@/components/weeklies/weekly-history';
import { WeeklyOverview } from '@/components/weeklies/weekly-overview';
import { WeeklyTabs } from '@/components/weeklies/weekly-tabs';
import { t } from '@/lib/i18n';
import { index as weekliesIndex } from '@/routes/weeklies';
import type { WeekliesIndexPageProps } from '@/types/weeklies';

/**
 * /weeklies (F-030 a F-040 y F-064 a F-069): pestañas «Resumen» (mi weekly, mi racha, mis clientes
 * y, para quien gestiona, la semana actual con quién falta y las exenciones) e «Histórico»
 * (semana activa y última cerrada destacadas con su equipo, y la tabla). El estado de proyectos
 * llega como pestaña en la 10.4 y el informe de cada semana, en la 10.3.
 */
export default function WeekliesIndex(props: WeekliesIndexPageProps) {
    const tabs = [
        {
            id: 'resumen',
            label: t('weeklies.tabs.summary'),
            href: weekliesIndex.url(),
        },
        {
            id: 'historico',
            label: t('weeklies.tabs.history'),
            href: weekliesIndex.url({ query: { pestana: 'historico' } }),
        },
    ];

    return (
        <>
            <Head title={t('weeklies.title')} />
            <div className="mx-auto flex w-full max-w-6xl min-w-0 flex-col gap-6 p-4 md:p-6">
                <Heading
                    as="h1"
                    title={t('weeklies.heading')}
                    description={t('weeklies.description')}
                />
                <WeeklyTabs
                    label={t('weeklies.tabs.label')}
                    tabs={tabs}
                    current={props.tab}
                />
                {props.tab === 'historico' ? (
                    <WeeklyHistory {...props} />
                ) : (
                    <WeeklyOverview {...props} />
                )}
            </div>
        </>
    );
}

WeekliesIndex.layout = {
    breadcrumbs: [{ title: t('weeklies.title'), href: weekliesIndex() }],
};
