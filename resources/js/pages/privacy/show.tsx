import { Head, Link, router } from '@inertiajs/react';
import {
    CircleCheck,
    Clock,
    Download,
    FilePenLine,
    ShieldCheck,
} from 'lucide-react';
import { useState } from 'react';
import { toastVisitErrors } from '@/components/admin/visit-errors';
import { KeywordText } from '@/components/keyword-text';
import {
    formatRetention,
    retentionLabel,
} from '@/components/privacy/retention';
import { SafeMarkdown } from '@/components/privacy/safe-markdown';
import { StatusBadge } from '@/components/styleguide/status-badges';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { acknowledge, show } from '@/routes/privacy';
import { index as myDataIndex } from '@/routes/privacy/exports';
import type { PrivacyShowProps } from '@/types/privacy';

/**
 * /privacidad (SPEC §15, D-075): el texto informativo para la plantilla, pintado con el markdown
 * saneado, los plazos de conservación vigentes y la lectura de la versión vigente («He leído la
 * información»). Los clientes del portal no llegan aquí.
 */
export default function PrivacyShow({
    notice,
    acknowledgement,
    retention,
    exportDays,
}: PrivacyShowProps) {
    const [processing, setProcessing] = useState(false);

    const accept = () =>
        router.post(
            acknowledge.url(),
            {},
            {
                preserveScroll: true,
                onError: toastVisitErrors,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );

    return (
        <>
            <Head title={t('privacy.page.title')} />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="space-y-2">
                    <h1 className="text-2xl font-normal tracking-tight">
                        <KeywordText text={t('privacy.page.heading')} />
                    </h1>
                    <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                        <span>
                            {t('privacy.page.version', {
                                version: notice.version,
                            })}
                        </span>
                        {notice.is_draft ? (
                            <StatusBadge tone="warning" icon={FilePenLine}>
                                {t('privacy.page.draft')}
                            </StatusBadge>
                        ) : null}
                        {acknowledgement.needed ? (
                            <StatusBadge tone="info" icon={Clock}>
                                {t('privacy.page.pending')}
                            </StatusBadge>
                        ) : (
                            <StatusBadge tone="success" icon={CircleCheck}>
                                {t('privacy.page.read')}
                            </StatusBadge>
                        )}
                    </div>
                </header>

                <div className="grid gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)] xl:items-start">
                    <div className="grid min-w-0 gap-6">
                        <Card>
                            <CardContent>
                                <article
                                    aria-label={t('privacy.page.text_label')}
                                    className="max-w-prose"
                                >
                                    <SafeMarkdown source={notice.markdown} />
                                </article>
                            </CardContent>
                        </Card>

                        <section
                            aria-labelledby="privacy-acknowledgement"
                            className="flex flex-col gap-3 rounded-md border bg-card p-4 sm:flex-row sm:items-center"
                            data-test="privacy-acknowledgement"
                        >
                            <ShieldCheck
                                aria-hidden="true"
                                className="hidden size-5 shrink-0 text-muted-foreground sm:block"
                                strokeWidth={1.5}
                            />
                            <div className="min-w-0 flex-1 space-y-1">
                                <h2
                                    id="privacy-acknowledgement"
                                    className="text-base font-medium"
                                >
                                    {t('privacy.page.acknowledgement_title')}
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    {acknowledgement.needed
                                        ? t(
                                              'privacy.page.acknowledgement_pending',
                                              {
                                                  version: notice.version,
                                              },
                                          )
                                        : t(
                                              'privacy.page.acknowledgement_done',
                                              {
                                                  version:
                                                      acknowledgement.version ??
                                                      notice.version,
                                                  date: formatDateTime(
                                                      acknowledgement.at,
                                                  ),
                                              },
                                          )}
                                </p>
                            </div>
                            {acknowledgement.needed ? (
                                <Button
                                    onClick={accept}
                                    disabled={processing}
                                    className="self-start sm:self-center"
                                >
                                    {processing ? (
                                        <Spinner />
                                    ) : (
                                        <CircleCheck aria-hidden="true" />
                                    )}
                                    {t('privacy.page.acknowledge')}
                                </Button>
                            ) : null}
                        </section>
                    </div>

                    <aside
                        className="grid gap-6"
                        aria-label={t('privacy.page.aside_label')}
                    >
                        <Card>
                            <CardHeader>
                                <CardTitle>
                                    <h2 className="text-base font-medium">
                                        {t('privacy.retention.title')}
                                    </h2>
                                </CardTitle>
                                <CardDescription>
                                    {t('privacy.retention.description')}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="grid gap-4">
                                <dl className="grid gap-3 text-sm">
                                    {retention.map((period) => (
                                        <div
                                            key={period.type}
                                            className="flex items-baseline justify-between gap-4 border-b pb-2 last:border-0 last:pb-0"
                                        >
                                            <dt className="text-muted-foreground">
                                                {retentionLabel(period.type)}
                                            </dt>
                                            <dd className="text-right font-medium">
                                                {formatRetention(period.months)}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                                <p className="text-sm text-muted-foreground">
                                    {t('privacy.retention.never')}
                                </p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>
                                    <h2 className="text-base font-medium">
                                        {t('privacy.page.my_data_title')}
                                    </h2>
                                </CardTitle>
                                <CardDescription>
                                    {t('privacy.page.my_data_description', {
                                        days: exportDays,
                                    })}
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <Button asChild variant="outline">
                                    <Link href={myDataIndex.url()}>
                                        <Download aria-hidden="true" />
                                        {t('privacy.page.my_data_link')}
                                    </Link>
                                </Button>
                            </CardContent>
                        </Card>
                    </aside>
                </div>
            </div>
        </>
    );
}

PrivacyShow.layout = {
    breadcrumbs: [{ title: t('privacy.page.title'), href: show() }],
};
