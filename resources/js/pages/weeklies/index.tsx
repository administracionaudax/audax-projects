import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { WeeklyPlaceholder } from '@/components/weeklies/weekly-placeholder';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { WeekliesIndexPageProps } from '@/types/weeklies';

/** /weeklies: histórico de semanas (F-064 a F-068). Esqueleto del contrato 10.1; lo completa 10.2. */
export default function WeekliesIndex({ cycles }: WeekliesIndexPageProps) {
    return (
        <>
            <Head title={t('weeklies.title')} />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-6">
                <Heading
                    as="h1"
                    title={t('weeklies.heading')}
                    description={t('weeklies.description')}
                />
                {cycles.length > 0 && (
                    <ul className="divide-y border">
                        {cycles.map((cycle) => (
                            <li
                                key={cycle.id}
                                className="flex flex-wrap items-center justify-between gap-2 p-3 text-sm"
                            >
                                <span>{cycle.label}</span>
                                <span className="text-muted-foreground">
                                    {t(`weeklies.cycle_status.${cycle.status}`)}
                                    {' · '}
                                    {t('weeklies.cycle.deadline', {
                                        date: formatDate(cycle.deadline_date),
                                    })}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
                <WeeklyPlaceholder delivery="10.2" />
            </div>
        </>
    );
}
