import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { WeeklyPlaceholder } from '@/components/weeklies/weekly-placeholder';
import { t } from '@/lib/i18n';
import type { MySpacePageProps } from '@/types/weeklies';

/** /mi-espacio: mi weekly y mis tareas (F-041 a F-063). Esqueleto del contrato 10.1 (10.2 y 10.6). */
export default function MySpace({ cycle }: MySpacePageProps) {
    return (
        <>
            <Head title={t('my_space.title')} />
            <div className="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
                <Heading
                    as="h1"
                    title={t('my_space.heading')}
                    description={t('my_space.description')}
                />
                <p className="text-sm text-muted-foreground">
                    {cycle === null ? t('my_space.no_cycle') : cycle.label}
                </p>
                <WeeklyPlaceholder delivery="10.2" />
            </div>
        </>
    );
}
