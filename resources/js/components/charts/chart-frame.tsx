import { ChartColumn, Table2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { useId, useState } from 'react';
import { Button } from '@/components/ui/button';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type ChartTableColumn = {
    key: string;
    label: string;
    numeric?: boolean;
};

export type ChartTableData = {
    columns: ReadonlyArray<ChartTableColumn>;
    rows: ReadonlyArray<Record<string, ReactNode> & { id: string }>;
};

type ChartFrameProps = {
    title: string;
    description?: string;
    /** Resumen para lectores de pantalla. */
    summary: string;
    /**
     * "img" para gráficas estáticas; "group" si la gráfica tiene su propio control de teclado.
     * Dentro de "img" todo es presentacional: las gráficas de Recharts van con
     * accessibilityLayer={false} para no meter un SVG enfocable (role="application") (UI-04).
     */
    chartRole?: 'img' | 'group';
    legend?: ReactNode;
    table: ChartTableData;
    children: ReactNode;
    className?: string;
};

/**
 * Contenedor de gráfica: título, leyenda y el conmutador "Ver como tabla"
 * (la alternativa accesible de toda gráfica). La altura la da el contenido, eje incluido.
 */
export function ChartFrame({
    title,
    description,
    summary,
    legend,
    table,
    children,
    className,
    chartRole = 'img',
}: ChartFrameProps) {
    const [asTable, setAsTable] = useState(false);
    const titleId = useId();

    return (
        <figure
            aria-labelledby={titleId}
            className={cn('flex min-w-0 flex-col gap-3', className)}
        >
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                    <figcaption id={titleId} className="text-base font-medium">
                        {title}
                    </figcaption>
                    {description ? (
                        <p className="text-sm text-muted-foreground">
                            {description}
                        </p>
                    ) : null}
                </div>
                {/*
                 * La etiqueta dice lo que hará el botón («Ver como tabla» / «Ver como gráfica»),
                 * así que no lleva aria-pressed: «Ver como gráfica, pulsado» sería contradictorio (UI-12).
                 */}
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => setAsTable((value) => !value)}
                >
                    {asTable ? (
                        <ChartColumn aria-hidden="true" />
                    ) : (
                        <Table2 aria-hidden="true" />
                    )}
                    {asTable ? t('charts.view_chart') : t('charts.view_table')}
                </Button>
            </div>

            {asTable ? (
                <ChartTable table={table} caption={title} />
            ) : (
                <>
                    {legend}
                    <div
                        role={chartRole}
                        aria-label={summary}
                        className="min-w-0"
                    >
                        {children}
                    </div>
                </>
            )}
        </figure>
    );
}

export function ChartTable({
    table,
    caption,
}: {
    table: ChartTableData;
    caption: string;
}) {
    return (
        <div
            className={cn(
                'max-h-80 overflow-auto rounded-md border',
                FOCUS_RING,
            )}
            role="region"
            aria-label={t('charts.table_label', { title: caption })}
            tabIndex={0}
        >
            <table className="w-full text-sm">
                <caption className="sr-only">{caption}</caption>
                <thead className="sticky top-0 bg-card">
                    <tr className="border-b">
                        {table.columns.map((column) => (
                            <th
                                key={column.key}
                                scope="col"
                                className={cn(
                                    'px-3 py-2 font-medium',
                                    column.numeric ? 'text-right' : 'text-left',
                                )}
                            >
                                {column.label}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {table.rows.map((row) => (
                        <tr
                            key={row.id}
                            className="border-b last:border-0 even:bg-muted"
                        >
                            {table.columns.map((column, index) =>
                                index === 0 ? (
                                    <th
                                        key={column.key}
                                        scope="row"
                                        className="px-3 py-1.5 text-left font-normal"
                                    >
                                        {row[column.key]}
                                    </th>
                                ) : (
                                    <td
                                        key={column.key}
                                        className={cn(
                                            'px-3 py-1.5',
                                            column.numeric &&
                                                'tabular text-right',
                                        )}
                                    >
                                        {row[column.key]}
                                    </td>
                                ),
                            )}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
