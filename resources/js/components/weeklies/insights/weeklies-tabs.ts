import { t } from '@/lib/i18n';
import { index as weekliesIndex, projectStatus } from '@/routes/weeklies';
import { edit as remindersEdit } from '@/routes/weeklies/reminders';

/**
 * Las pestañas de /weeklies (F-064): «Resumen», «Histórico», con el módulo encendido «Estado de
 * proyectos» (10.4) y, para quien gestiona la Weekly, «Avisos» (10.5). Las dos últimas son su
 * propia página.
 */
export function weekliesTabs(projectStatusEnabled: boolean, manage = false) {
    return [
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
        ...(projectStatusEnabled
            ? [
                  {
                      id: 'estado-proyectos',
                      label: t('weeklies.tabs.project_status'),
                      href: projectStatus.url(),
                  },
              ]
            : []),
        ...(manage
            ? [
                  {
                      id: 'avisos',
                      label: t('weekly_reminders.tab'),
                      href: remindersEdit.url(),
                  },
              ]
            : []),
    ];
}
