import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { WeeklyPlaceholder } from '@/components/weeklies/weekly-placeholder';
import { t } from '@/lib/i18n';

/** /ia: asistente IA (F-146 y F-147). Esqueleto del contrato 10.1 (10.6). */
export default function Assistant() {
    return (
        <>
            <Head title={t('assistant.title')} />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
                <Heading
                    as="h1"
                    title={t('assistant.heading')}
                    description={t('assistant.description')}
                />
                <WeeklyPlaceholder delivery="10.6" />
            </div>
        </>
    );
}
