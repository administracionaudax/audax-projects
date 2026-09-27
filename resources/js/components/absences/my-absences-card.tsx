import { Link } from '@inertiajs/react';
import { CalendarPlus } from 'lucide-react';
import {
    AbsenceStatusBadge,
    AbsenceTypeLabel,
    absencePeriodLabel,
} from '@/components/absences/absence-meta';
import type {
    AbsenceSummaryItem,
    MyAbsencesSummary,
} from '@/components/absences/types';
import { Button } from '@/components/ui/button';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as absencesIndex } from '@/routes/absences';

function Group({
    title,
    items,
}: {
    title: string;
    items: AbsenceSummaryItem[];
}) {
    if (items.length === 0) {
        return null;
    }

    return (
        <div className="grid gap-1">
            <h3 className="text-sm font-medium">
                {title}{' '}
                <span className="text-muted-foreground">({items.length})</span>
            </h3>
            <ul className="divide-y rounded-md border">
                {items.map((item) => (
                    <li
                        key={item.id}
                        className="flex flex-wrap items-center justify-between gap-2 px-2 py-1.5 text-sm"
                        data-test="home-absence"
                    >
                        <span className="min-w-0">
                            <AbsenceTypeLabel type={item.type} />
                            <span className="tabular block text-xs text-muted-foreground">
                                {absencePeriodLabel(item)}
                            </span>
                        </span>
                        <AbsenceStatusBadge status={item.status} />
                    </li>
                ))}
            </ul>
        </div>
    );
}

/**
 * Contenido de la tarjeta «Mis ausencias» de Inicio (SPEC §5.1): solicitudes pendientes y
 * próximas aprobadas (también la que está en curso), con el enlace para solicitar otra.
 */
export function MyAbsencesCard({ absences }: { absences: MyAbsencesSummary }) {
    const empty =
        absences.pending.length === 0 && absences.upcoming.length === 0;

    return (
        <>
            {empty ? (
                <p className="text-sm text-muted-foreground">
                    {t('absences.home.empty')}
                </p>
            ) : (
                <>
                    <Group
                        title={t('absences.home.pending')}
                        items={absences.pending}
                    />
                    <Group
                        title={t('absences.home.upcoming')}
                        items={absences.upcoming}
                    />
                </>
            )}
            <div className="mt-auto flex flex-wrap items-center gap-x-4 gap-y-2">
                <Button asChild variant="outline" size="sm">
                    <Link href={absencesIndex.url({ query: { solicitar: 1 } })}>
                        <CalendarPlus aria-hidden="true" />
                        {t('absences.home.request')}
                    </Link>
                </Button>
                <Link
                    href={absencesIndex.url()}
                    className={cn(
                        'rounded-sm text-sm text-primary-text hover:underline',
                        FOCUS_RING,
                    )}
                >
                    {t('absences.home.all')}
                </Link>
            </div>
        </>
    );
}
