import { CircleAlert, RefreshCw } from 'lucide-react';
import { formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { HoldedSyncSummary } from '@/types';

/** Cuándo se leyó Holded por última vez (y si falló). */
export function LastSync({ sync }: { sync: HoldedSyncSummary | null }) {
    if (sync === null) {
        return (
            <p className="flex items-center gap-1.5 text-sm text-muted-foreground">
                <RefreshCw aria-hidden="true" className="size-4" />
                {t('billing.sync.never')}
            </p>
        );
    }

    const failed = sync.status === 'failed';

    return (
        <p className="flex items-center gap-1.5 text-sm text-muted-foreground">
            {failed ? (
                <CircleAlert
                    aria-hidden="true"
                    className="size-4 text-danger"
                />
            ) : (
                <RefreshCw aria-hidden="true" className="size-4" />
            )}
            {t(
                failed
                    ? 'billing.sync.last_failed'
                    : sync.status === 'running'
                      ? 'billing.sync.running'
                      : 'billing.sync.last',
                { date: formatDateTime(sync.finished_at ?? sync.started_at) },
            )}
        </p>
    );
}
