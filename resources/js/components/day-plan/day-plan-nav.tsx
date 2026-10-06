import { WeeklyTabs } from '@/components/weeklies/weekly-tabs';
import { t } from '@/lib/i18n';

export type DayPlanTab = 'mine' | 'team' | 'week';

/**
 * Pestañas del plan del día (docs/PLAN-CARGAS.md §4.3): Mi día, Equipo hoy y Semana, como enlaces
 * con la actual marcada (aria-current). Toda la plantilla ve el equipo (P1 a): las cifras de cada
 * persona solo le llegan a quien puede verlas.
 */
export function DayPlanNav({
    current,
    date,
}: {
    current: DayPlanTab;
    /** Fecha que se conserva al cambiar de pestaña (Mi día y Equipo hoy). */
    date?: string;
}) {
    const query = date ? `?fecha=${date}` : '';

    return (
        <WeeklyTabs
            label={t('day_plan.tabs.label')}
            current={current}
            tabs={[
                {
                    id: 'mine',
                    label: t('day_plan.tabs.mine'),
                    href: `/dia${query}`,
                },
                {
                    id: 'team',
                    label: t('day_plan.tabs.team'),
                    href: `/dia/equipo${query}`,
                },
                {
                    id: 'week',
                    label: t('day_plan.tabs.week'),
                    href: '/dia/semana',
                },
            ]}
        />
    );
}
