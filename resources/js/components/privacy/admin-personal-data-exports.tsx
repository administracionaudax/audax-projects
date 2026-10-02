import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    isInProgress,
    PersonalDataExportList,
    RequestExportButton,
    usePollWhileInProgress,
} from '@/components/privacy/personal-data-exports';
import { t } from '@/lib/i18n';
import { store } from '@/routes/admin/privacy/exports';
import type { PersonalDataExportRow } from '@/types/privacy';

/**
 * Ficha de la persona en la administración (D-075): el admin prepara la exportación de sus datos
 * personales (una petición de acceso o de portabilidad) y la descarga desde aquí.
 */
export function AdminPersonalDataExports({
    userId,
    userName,
    rows,
}: {
    userId: number;
    userName: string;
    rows: PersonalDataExportRow[];
}) {
    usePollWhileInProgress(rows, ['personalDataExports']);

    return (
        <Card data-test="admin-personal-data-exports">
            <CardHeader>
                <CardTitle>
                    <h2 className="text-base font-medium">
                        {t('privacy.admin_user.title')}
                    </h2>
                </CardTitle>
                <CardDescription>
                    {t('privacy.admin_user.description', { name: userName })}
                </CardDescription>
            </CardHeader>
            <CardContent className="grid gap-4">
                <RequestExportButton
                    url={store.url(userId)}
                    label={t('privacy.admin_user.request')}
                    disabled={rows.some(isInProgress)}
                    disabledHint={t('privacy.admin_user.in_progress')}
                />
                <PersonalDataExportList
                    rows={rows}
                    emptyTitle={t('privacy.admin_user.empty')}
                />
            </CardContent>
        </Card>
    );
}
