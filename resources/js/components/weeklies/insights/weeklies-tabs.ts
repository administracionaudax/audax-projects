import { t } from '@/lib/i18n';
import { index as weekliesIndex, projectStatus } from '@/routes/weeklies';

/**
 * Las pestañas de /weeklies (F-064): «Resumen», «Histórico» y, con el módulo encendido, «Estado de
 * proyectos» (10.4), que es su propia página.
 */
export function weekliesTabs(projectStatusEnabled: boolean) {
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
    ];
}
