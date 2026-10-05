import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { WeeklyPlaceholder } from '@/components/weeklies/weekly-placeholder';
import { t } from '@/lib/i18n';

/**
 * /ayuda: centro de ayuda y sugerencias (F-148 a F-170). Esqueleto del contrato 10.1 (10.7); las
 * props son HelpPageProps (@/types/weeklies).
 */
export default function Help() {
    return (
        <>
            <Head title={t('help_center.title')} />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-6">
                <Heading
                    as="h1"
                    title={t('help_center.heading')}
                    description={t('help_center.description')}
                />
                <WeeklyPlaceholder delivery="10.7" />
            </div>
        </>
    );
}
