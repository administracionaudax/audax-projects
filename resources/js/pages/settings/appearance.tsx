import { Head } from '@inertiajs/react';
import AppearanceTabs from '@/components/appearance-tabs';
import Heading from '@/components/heading';
import { t } from '@/lib/i18n';
import { edit as editAppearance } from '@/routes/appearance';

export default function Appearance() {
    return (
        <>
            <Head title={t('appearance.title')} />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('appearance.heading')}
                    description={t('appearance.description')}
                />
                <AppearanceTabs />
            </div>
        </>
    );
}

Appearance.layout = {
    breadcrumbs: [{ title: t('appearance.title'), href: editAppearance() }],
};
