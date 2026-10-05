import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { WeeklyPlaceholder } from '@/components/weeklies/weekly-placeholder';
import { t } from '@/lib/i18n';
import type { WeeklyShowPageProps } from '@/types/weeklies';

/** /weeklies/{cycle}: informe de la semana (F-072 a F-091). Esqueleto del contrato 10.1 (10.3). */
export default function WeeklyShow({ cycle }: WeeklyShowPageProps) {
    return (
        <>
            <Head title={cycle.label} />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-6">
                <Heading
                    as="h1"
                    title={cycle.label}
                    description={t('weeklies.report.title')}
                />
                {cycle.report === null ? (
                    <p className="text-sm text-muted-foreground">
                        {t('weeklies.report.empty')}
                    </p>
                ) : (
                    <section className="space-y-2">
                        <h2 className="text-lg">
                            {t('weeklies.report.global_summary')}
                        </h2>
                        <p className="text-sm whitespace-pre-line">
                            {cycle.report.global_summary}
                        </p>
                    </section>
                )}
                <WeeklyPlaceholder delivery="10.3" />
            </div>
        </>
    );
}
