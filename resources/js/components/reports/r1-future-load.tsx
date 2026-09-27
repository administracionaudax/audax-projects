import { Link } from '@inertiajs/react';
import { ArrowRight, Users } from 'lucide-react';
import { LoadCell } from '@/components/charts/load-cell';
import { EmptyState } from '@/components/empty-state';
import type { R1FutureLoad as FutureLoad } from '@/components/reports/r1-types';
import { Skeleton } from '@/components/ui/skeleton';
import { columnHeading } from '@/components/workload/workload-labels';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

const HEADER_CLASS =
    'px-1 text-left align-bottom text-xs font-normal text-muted-foreground';

/**
 * «Carga futura» del informe de departamento: la carga planificada de las próximas cuatro semanas
 * de cada persona frente a su capacidad, con el mismo semáforo que la vista Carga (D-051), la
 * fila del equipo y el enlace a /carga con el departamento. La cabecera de cada columna es su
 * semana; la de cada fila, la persona: los lectores de pantalla leen las dos al recorrer las celdas.
 */
export function R1FutureLoad({ load }: { load: FutureLoad }) {
    if (load.people.length === 0) {
        return (
            <EmptyState
                icon={Users}
                title={t('reports_r1.department.future_load_none')}
            />
        );
    }

    return (
        <div className="grid gap-3">
            <div className="overflow-x-auto">
                <table className="w-full min-w-[640px] border-separate border-spacing-1 text-sm">
                    <caption className="sr-only">
                        {t('reports_r1.department.future_load_caption')}
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col" className={HEADER_CLASS}>
                                {t('reports_r1.department.future_load_person')}
                            </th>
                            {load.columns.map((column) => {
                                const heading = columnHeading(column, true);

                                return (
                                    <th
                                        key={column.key}
                                        scope="col"
                                        className={HEADER_CLASS}
                                    >
                                        <span className="block">
                                            {heading.top}
                                        </span>
                                        <span className="tabular block">
                                            {heading.bottom}
                                        </span>
                                    </th>
                                );
                            })}
                            <th scope="col" className={HEADER_CLASS}>
                                {t('reports_r1.department.future_load_total')}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {load.people.map((person) => (
                            <tr key={person.id}>
                                <th
                                    scope="row"
                                    className="max-w-48 truncate px-1 text-left font-normal"
                                >
                                    {person.name}
                                </th>
                                {person.cells.map((cell, index) => (
                                    <td key={load.columns[index]?.key ?? index}>
                                        <LoadCell
                                            planned={cell.planned}
                                            capacity={cell.capacity}
                                        />
                                    </td>
                                ))}
                                <td>
                                    <LoadCell
                                        planned={person.total.planned}
                                        capacity={person.total.capacity}
                                    />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot>
                        <tr>
                            <th
                                scope="row"
                                className="px-1 text-left font-normal text-muted-foreground"
                            >
                                {t('reports_r1.department.future_load_team')}
                            </th>
                            {load.totals.map((cell, index) => (
                                <td key={load.columns[index]?.key ?? index}>
                                    <LoadCell
                                        planned={cell.planned}
                                        capacity={cell.capacity}
                                    />
                                </td>
                            ))}
                            <td>
                                <LoadCell
                                    planned={load.total.planned}
                                    capacity={load.total.capacity}
                                />
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <Link
                href={load.url}
                className={cn(
                    'inline-flex items-center gap-1.5 self-start rounded-sm text-sm text-primary-text hover:underline',
                    FOCUS_RING,
                )}
            >
                {t('reports_r1.department.future_load_open')}
                <ArrowRight aria-hidden="true" className="size-4" />
            </Link>
        </div>
    );
}

/** Mientras llega la prop diferida. */
export function R1FutureLoadSkeleton() {
    return (
        <div aria-busy="true" className="grid gap-2">
            <span className="sr-only">
                {t('reports_r1.department.future_load_loading')}
            </span>
            <Skeleton className="h-10 w-full" />
            <Skeleton className="h-10 w-full" />
            <Skeleton className="h-10 w-full" />
        </div>
    );
}
