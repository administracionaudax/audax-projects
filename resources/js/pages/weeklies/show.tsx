import { Head } from '@inertiajs/react';
import { useWeeklyLive } from '@/components/weeklies/use-weekly-live';
import { WeeklyReportView } from '@/components/weeklies/weekly-report-view';
import { t } from '@/lib/i18n';
import {
    index as weekliesIndex,
    show as weekliesShow,
} from '@/routes/weeklies';
import type { WeeklyShowPageProps } from '@/types/weeklies';

/** /weeklies/{cycle}: el informe de la semana (F-072 a F-091, entrega 10.3). */
export default function WeeklyShow(props: WeeklyShowPageProps) {
    // En vivo (D-229): envíos, exenciones, plazo, cierre, informe y satisfacción de esta semana.
    useWeeklyLive({
        cycleId: props.cycle.id,
        only: [
            'cycle',
            'team',
            'reports',
            'stale',
            'submitted_count',
            'close',
            'can',
        ],
    });

    return (
        <>
            <Head
                title={`${props.cycle.number} · ${t('weeklies.report.title')}`}
            />
            <WeeklyReportView {...props} />
        </>
    );
}

WeeklyShow.layout = (props: WeeklyShowPageProps) => ({
    breadcrumbs: [
        { title: t('weeklies.title'), href: weekliesIndex() },
        { title: props.cycle.number, href: weekliesShow(props.cycle.id) },
    ],
});
