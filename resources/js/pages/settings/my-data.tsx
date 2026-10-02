import { Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import {
    PersonalDataExportList,
    RequestExportButton,
    usePollWhileInProgress,
} from '@/components/privacy/personal-data-exports';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { show as privacyShow } from '@/routes/privacy';
import { index as myDataIndex, store } from '@/routes/privacy/exports';
import type { MyDataProps } from '@/types/privacy';

/**
 * /ajustes/mis-datos (D-075): la persona prepara una copia de sus datos personales (ZIP con JSON y
 * CSV) y la descarga con un enlace firmado mientras no caduque. Una sola en curso: el botón se
 * desactiva y la lista se refresca sola hasta que termina.
 */
export default function MyData({
    exports,
    canRequest,
    exportDays,
}: MyDataProps) {
    usePollWhileInProgress(exports, ['exports', 'canRequest']);
    const latest = exports[0] ?? null;

    return (
        <>
            <Head title={t('privacy.my_data.title')} />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('privacy.my_data.heading')}
                    description={t('privacy.my_data.description')}
                />

                <ul className="list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                    <li>{t('privacy.my_data.contents')}</li>
                    <li>{t('privacy.my_data.formats')}</li>
                    <li>
                        {t('privacy.my_data.availability', {
                            days: exportDays,
                        })}
                    </li>
                </ul>

                <RequestExportButton
                    url={store.url()}
                    label={t('privacy.my_data.request')}
                    disabled={!canRequest}
                    disabledHint={t('privacy.my_data.in_progress')}
                />

                {/* Anuncia a los lectores de pantalla cuándo cambia el estado de la última. */}
                <p role="status" className="sr-only">
                    {latest
                        ? t('privacy.my_data.latest_status', {
                              status: latest.status_label,
                          })
                        : ''}
                </p>

                <section className="space-y-3" aria-labelledby="my-data-list">
                    <h3 id="my-data-list" className="text-sm font-medium">
                        {t('privacy.my_data.list_heading')}
                    </h3>
                    <PersonalDataExportList
                        rows={exports}
                        emptyTitle={t('privacy.my_data.empty')}
                        emptyDescription={t(
                            'privacy.my_data.empty_description',
                        )}
                    />
                </section>

                <p className="text-sm text-muted-foreground">
                    {t('privacy.my_data.privacy_hint')}{' '}
                    <Link
                        href={privacyShow.url()}
                        className={cn(
                            'rounded-xs text-primary-text underline decoration-primary-text/40 underline-offset-4 hover:decoration-current',
                            FOCUS_RING,
                        )}
                    >
                        {t('privacy.my_data.privacy_link')}
                    </Link>
                </p>
            </div>
        </>
    );
}

MyData.layout = {
    breadcrumbs: [{ title: t('privacy.my_data.title'), href: myDataIndex() }],
};
