import { router } from '@inertiajs/react';
import { Clock, RotateCw, TriangleAlert } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import { NativeSelect } from '@/components/admin/native-select';
import { EmptyState } from '@/components/empty-state';
import { ListPagination } from '@/components/projects-list/list-pagination';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate, formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { show } from '@/routes/portal/banks';
import type { ProjectsPaginated } from '@/types';
import type { PortalBankMonth } from '@/types/portal';
import { formatMonth, monthParam } from './format';
import type { PortalBankEntry } from './types';

/**
 * Estado de las visitas de Inertia en el detalle (al cambiar de mes o de página, las entradas se
 * vuelven a pedir): cargando o con un error de red.
 */
export function usePortalVisitState(): { loading: boolean; failed: boolean } {
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        const offStart = router.on('start', () => {
            setLoading(true);
            setFailed(false);
        });
        const offFinish = router.on('finish', () => setLoading(false));
        const offError = router.on('networkError', () => {
            setLoading(false);
            setFailed(true);
        });

        return () => {
            offStart();
            offFinish();
            offError();
        };
    }, []);

    return { loading, failed };
}

/**
 * Horas de la bolsa que ve el cliente (SPEC §11), paginadas y con un filtro por mes (?mes=AAAA-MM):
 * mientras se cargan, un aviso con el indicador y la tabla atenuada (aria-busy); si falla la red,
 * un aviso con icono, texto y «Reintentar».
 */
export function PortalBankEntries({
    bankId,
    entries,
    months,
    month,
}: {
    bankId: number;
    entries: ProjectsPaginated<PortalBankEntry>;
    /** Meses con horas (PortalBankFigures::byMonth), de las opciones del filtro. */
    months: ReadonlyArray<PortalBankMonth>;
    /** Mes filtrado (AAAA-MM) o null. */
    month: string | null;
}) {
    const { loading, failed } = usePortalVisitState();
    const selectId = useId();
    const current = month
        ? months.find((item) => monthParam(item.month) === month)
        : undefined;

    const changeMonth = (value: string) => {
        router.get(show.url(bankId), value ? { mes: value } : {}, {
            preserveScroll: true,
            preserveState: true,
            only: ['entries', 'filters'],
        });
    };

    return (
        <div className="grid min-w-0 gap-4">
            {months.length > 0 ? (
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="grid w-full gap-1.5 sm:w-64">
                        <Label htmlFor={selectId}>
                            {t('portal_banks.entries.month_filter')}
                        </Label>
                        <NativeSelect
                            id={selectId}
                            value={month ?? ''}
                            onChange={(event) =>
                                changeMonth(event.target.value)
                            }
                            data-test="portal-entries-month"
                        >
                            <option value="">
                                {t('portal_banks.entries.all_months')}
                            </option>
                            {[...months].reverse().map((item) => (
                                <option
                                    key={item.month}
                                    value={monthParam(item.month)}
                                >
                                    {formatMonth(item.month)}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    {current ? (
                        <p className="text-sm text-muted-foreground">
                            {current.overage_minutes > 0
                                ? t(
                                      'portal_banks.entries.month_total_overage',
                                      {
                                          month: formatMonth(current.month),
                                          minutes: formatMinutes(
                                              current.within_minutes +
                                                  current.overage_minutes,
                                          ),
                                          overage: formatMinutes(
                                              current.overage_minutes,
                                          ),
                                      },
                                  )
                                : t('portal_banks.entries.month_total', {
                                      month: formatMonth(current.month),
                                      minutes: formatMinutes(
                                          current.within_minutes,
                                      ),
                                  })}
                        </p>
                    ) : null}
                </div>
            ) : null}

            <div aria-live="polite">
                {loading ? (
                    <p className="flex items-center gap-2 text-sm text-muted-foreground">
                        <Spinner />
                        {t('portal_banks.entries.loading')}
                    </p>
                ) : null}
            </div>

            {failed ? (
                <Alert variant="destructive" role="alert">
                    <TriangleAlert aria-hidden="true" />
                    <AlertDescription className="flex flex-wrap items-center gap-3">
                        {t('portal_banks.entries.error')}
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => router.reload()}
                        >
                            <RotateCw aria-hidden="true" />
                            {t('portal_banks.entries.retry')}
                        </Button>
                    </AlertDescription>
                </Alert>
            ) : null}

            <div
                aria-busy={loading || undefined}
                className={cn(
                    'grid min-w-0 gap-4 transition-opacity',
                    loading && 'opacity-60',
                )}
            >
                <PortalBankEntriesTable
                    entries={entries.data}
                    emptyTitle={t(
                        month
                            ? 'portal_banks.entries.empty_month'
                            : 'portal_banks.entries.empty',
                    )}
                />
                <ListPagination
                    page={entries}
                    label={t('portal_banks.entries.pages')}
                />
            </div>
        </div>
    );
}

/**
 * Tabla de horas: fecha, tarea, tipo, persona (como la ve el cliente), duración (con la parte de
 * exceso en rojo, con icono) y descripción. En el móvil se desplaza dentro de su marco, sin
 * desbordar la página. Nunca importes, tarifas ni costes.
 */
export function PortalBankEntriesTable({
    entries,
    emptyTitle,
}: {
    entries: PortalBankEntry[];
    emptyTitle: string;
}) {
    if (entries.length === 0) {
        return <EmptyState icon={Clock} title={emptyTitle} />;
    }

    return (
        <div
            className={cn('overflow-x-auto rounded-md border', FOCUS_RING)}
            role="region"
            aria-label={t('portal_banks.entries.table')}
            tabIndex={0}
            data-test="portal-entries"
        >
            <table className="w-full min-w-[48rem] text-sm">
                <caption className="sr-only">
                    {t('portal_banks.entries.title')}
                </caption>
                <thead>
                    <tr className="border-b text-left">
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('portal_banks.entries.date')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('portal_banks.entries.task')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('portal_banks.entries.type')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('portal_banks.entries.person')}
                        </th>
                        <th
                            scope="col"
                            className="px-3 py-2 text-right font-medium"
                        >
                            {t('portal_banks.entries.duration')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('portal_banks.entries.note')}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {entries.map((entry) => (
                        <tr
                            key={entry.id}
                            className="border-b align-top last:border-0 even:bg-muted"
                        >
                            <td className="tabular px-3 py-2 whitespace-nowrap">
                                {formatDate(entry.date)}
                            </td>
                            <td className="min-w-48 px-3 py-2 break-words">
                                {entry.task}
                            </td>
                            <td className="px-3 py-2 whitespace-nowrap">
                                {entry.type ? (
                                    <span className="inline-flex items-center gap-2">
                                        <span
                                            aria-hidden="true"
                                            className="size-2.5 shrink-0 rounded-full"
                                            style={{
                                                backgroundColor:
                                                    entry.type.color,
                                            }}
                                        />
                                        {entry.type.name}
                                    </span>
                                ) : (
                                    <span className="text-muted-foreground">
                                        {t('portal_banks.entries.no_type')}
                                    </span>
                                )}
                            </td>
                            <td className="px-3 py-2 whitespace-nowrap">
                                {entry.person}
                            </td>
                            <td className="tabular px-3 py-2 text-right whitespace-nowrap">
                                {formatMinutes(entry.minutes)}
                                {entry.overage_minutes > 0 ? (
                                    <span className="mt-0.5 flex items-center justify-end gap-1 text-xs font-medium text-danger">
                                        <TriangleAlert
                                            aria-hidden="true"
                                            className="size-3.5"
                                        />
                                        {t('portal_banks.overage', {
                                            minutes: formatMinutes(
                                                entry.overage_minutes,
                                            ),
                                        })}
                                    </span>
                                ) : null}
                            </td>
                            <td className="min-w-56 px-3 py-2 break-words whitespace-pre-line text-muted-foreground">
                                {entry.description}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
