import { Head, router } from '@inertiajs/react';
import { FileDown, FileSpreadsheet, FileText } from 'lucide-react';
import { useId, useState } from 'react';
import { PeopleFrame } from '@/components/people/people-ui';
import { HashText } from '@/components/people/register-ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import {
    exportKindLabel,
    formatBytes,
    reportDownloadUrl,
    reportQuery,
} from '@/lib/people-register';
import type { ReportQuery } from '@/lib/people-register';
import { cn } from '@/lib/utils';
import { index } from '@/routes/people/reports';
import type { ReportKind, ReportsPageProps } from '@/types/people-register';

/**
 * «Informes» del registro (`/personas/informes`, PLAN-FASE-11 §6.3.2; D-351; W-089 a W-093), solo
 * RR. HH.: «Registro mensual de la jornada», «Anexo de horas», «Presencia diaria», «Presencia
 * mensual», «Fichajes» e «Incidencias», con su ámbito (toda la plantilla, un departamento o unas
 * personas). En pantalla, las primeras filas; en PDF, Excel y CSV, todo, con la huella del contenido
 * dentro y la del fichero anotada.
 */
export default function ReportsPage(props: ReportsPageProps) {
    const id = useId();
    const current =
        props.kinds.find((kind) => kind.value === props.kind) ?? props.kinds[0];
    const [query, setQuery] = useState<ReportQuery>({
        kind: props.kind,
        monthly: current.monthly,
        month: props.filters.month,
        from: props.filters.from,
        to: props.filters.to,
        userIds: props.filters.user_ids,
        departmentId: props.filters.department_id,
    });

    const apply = (next: ReportQuery) => {
        setQuery(next);
        router.get(
            index.url({ query: { informe: next.kind, ...reportQuery(next) } }),
            {},
            { preserveScroll: true, preserveState: true },
        );
    };

    const setKind = (value: ReportKind) => {
        const kind = props.kinds.find((item) => item.value === value);
        apply({ ...query, kind: value, monthly: kind?.monthly ?? false });
    };

    return (
        <>
            <Head title={t('people.reports.title')} />
            <PeopleFrame
                section="reports"
                title={t('people.reports.title')}
                description={t('people.reports.description')}
            >
                <div
                    role="group"
                    aria-label={t('people.reports.kinds_label')}
                    className="flex flex-wrap gap-2"
                >
                    {props.kinds.map((kind) => (
                        <button
                            key={kind.value}
                            type="button"
                            aria-pressed={kind.value === props.kind}
                            onClick={() => setKind(kind.value)}
                            className={cn(
                                'rounded-md border px-3 py-1.5 text-sm',
                                kind.value === props.kind
                                    ? 'border-primary bg-accent'
                                    : 'hover:bg-accent/50',
                            )}
                            data-test={`report-kind-${kind.value}`}
                        >
                            {kind.title}
                        </button>
                    ))}
                </div>

                <section
                    aria-labelledby={`${id}-filters`}
                    className="grid gap-3 rounded-md border p-4"
                >
                    <h2 id={`${id}-filters`} className="sr-only">
                        {t('people.reports.filters')}
                    </h2>
                    <div className="flex flex-wrap items-end gap-3">
                        {query.monthly ? (
                            <div className="grid gap-1.5">
                                <Label htmlFor={`${id}-month`}>
                                    {t('people.reports.month')}
                                </Label>
                                <Input
                                    id={`${id}-month`}
                                    type="month"
                                    value={query.month}
                                    max={props.today.slice(0, 7)}
                                    onChange={(event) =>
                                        event.target.value &&
                                        apply({
                                            ...query,
                                            month: event.target.value,
                                        })
                                    }
                                    className="w-44"
                                    data-test="report-month"
                                />
                            </div>
                        ) : (
                            <>
                                <div className="grid gap-1.5">
                                    <Label htmlFor={`${id}-from`}>
                                        {t('people.register.from')}
                                    </Label>
                                    <Input
                                        id={`${id}-from`}
                                        type="date"
                                        value={query.from}
                                        max={props.today}
                                        onChange={(event) =>
                                            event.target.value &&
                                            apply({
                                                ...query,
                                                from: event.target.value,
                                            })
                                        }
                                        className="w-44"
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor={`${id}-to`}>
                                        {t('people.register.to')}
                                    </Label>
                                    <Input
                                        id={`${id}-to`}
                                        type="date"
                                        value={query.to}
                                        max={props.today}
                                        onChange={(event) =>
                                            event.target.value &&
                                            apply({
                                                ...query,
                                                to: event.target.value,
                                            })
                                        }
                                        className="w-44"
                                    />
                                </div>
                            </>
                        )}
                        <div className="grid gap-1.5">
                            <Label htmlFor={`${id}-department`}>
                                {t('people.team.department')}
                            </Label>
                            <Select
                                value={
                                    query.departmentId
                                        ? String(query.departmentId)
                                        : 'all'
                                }
                                onValueChange={(value) =>
                                    apply({
                                        ...query,
                                        departmentId:
                                            value === 'all'
                                                ? null
                                                : Number(value),
                                        userIds: [],
                                    })
                                }
                            >
                                <SelectTrigger
                                    id={`${id}-department`}
                                    className="w-60"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        {t('people.team.all_departments')}
                                    </SelectItem>
                                    {props.departments.map((department) => (
                                        <SelectItem
                                            key={department.id}
                                            value={String(department.id)}
                                        >
                                            {department.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor={`${id}-person`}>
                                {t('people.reports.person')}
                            </Label>
                            <Select
                                value={
                                    query.userIds.length === 1
                                        ? String(query.userIds[0])
                                        : 'all'
                                }
                                onValueChange={(value) =>
                                    apply({
                                        ...query,
                                        userIds:
                                            value === 'all'
                                                ? []
                                                : [Number(value)],
                                        departmentId: null,
                                    })
                                }
                            >
                                <SelectTrigger
                                    id={`${id}-person`}
                                    className="w-56"
                                    data-test="report-person"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        {t('people.reports.everyone')}
                                    </SelectItem>
                                    {props.people.map((person) => (
                                        <SelectItem
                                            key={person.id}
                                            value={String(person.id)}
                                        >
                                            {person.active
                                                ? person.name
                                                : t('people.reports.inactive', {
                                                      name: person.name,
                                                  })}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {(
                            [
                                [
                                    'pdf',
                                    FileText,
                                    t('people.register.download_pdf'),
                                ],
                                [
                                    'xlsx',
                                    FileSpreadsheet,
                                    t('people.register.download_xlsx'),
                                ],
                                [
                                    'csv',
                                    FileDown,
                                    t('people.register.download_csv'),
                                ],
                            ] as const
                        ).map(([format, Icon, label]) => (
                            <Button
                                key={format}
                                asChild
                                variant={
                                    format === 'pdf' ? 'default' : 'outline'
                                }
                            >
                                <a
                                    href={reportDownloadUrl(query, format)}
                                    data-test={`report-download-${format}`}
                                >
                                    <Icon aria-hidden="true" />
                                    {label}
                                </a>
                            </Button>
                        ))}
                        <HashText
                            hash={props.preview.content_hash}
                            label={t('people.reports.content_hash')}
                            className="ml-auto"
                        />
                    </div>
                </section>

                <section
                    aria-labelledby={`${id}-preview`}
                    className="grid gap-2"
                >
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 id={`${id}-preview`} className="text-lg">
                            {props.preview.title}
                        </h2>
                        <p
                            className="text-xs text-muted-foreground"
                            data-test="report-total"
                        >
                            {props.preview.total > props.preview.rows.length
                                ? t('people.reports.preview_partial', {
                                      shown: props.preview.rows.length,
                                      total: props.preview.total,
                                  })
                                : t('people.reports.preview_all', {
                                      total: props.preview.total,
                                  })}
                        </p>
                    </div>
                    {props.preview.rows.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('people.reports.empty')}
                        </p>
                    ) : (
                        <div
                            className="max-h-[32rem] overflow-auto rounded-md border"
                            tabIndex={0}
                            role="region"
                            aria-label={props.preview.title}
                        >
                            <table
                                className="w-full text-xs"
                                data-test="report-preview"
                            >
                                <caption className="sr-only">
                                    {props.preview.title}
                                </caption>
                                <thead className="sticky top-0 bg-background">
                                    <tr className="border-b text-left text-muted-foreground">
                                        {props.preview.headers.map((header) => (
                                            <th
                                                key={header}
                                                scope="col"
                                                className="px-2 py-1.5 font-medium whitespace-nowrap"
                                            >
                                                {header}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {props.preview.rows.map((row, rowIndex) => (
                                        <tr
                                            key={rowIndex}
                                            className="border-b last:border-0"
                                        >
                                            {row.map((cell, cellIndex) => (
                                                <td
                                                    key={cellIndex}
                                                    className={cn(
                                                        'px-2 py-1 whitespace-nowrap',
                                                        typeof cell ===
                                                            'number' &&
                                                            'tabular text-right',
                                                    )}
                                                >
                                                    {cell === null ||
                                                    cell === undefined
                                                        ? ''
                                                        : String(cell)}
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <section
                    aria-labelledby={`${id}-exports`}
                    className="grid gap-2"
                >
                    <div className="space-y-1">
                        <h2 id={`${id}-exports`} className="text-lg">
                            {t('people.reports.exports_title')}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {t('people.reports.exports_hint')}
                        </p>
                    </div>
                    {props.exports.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('people.reports.no_exports')}
                        </p>
                    ) : (
                        <ul
                            className="divide-y rounded-md border text-sm"
                            data-test="report-exports"
                        >
                            {props.exports.map((item) => (
                                <li
                                    key={item.id}
                                    className="grid gap-1 px-3 py-2 sm:grid-cols-[1fr_auto] sm:items-center"
                                >
                                    <span className="min-w-0">
                                        <span className="block truncate">
                                            {exportKindLabel(item.kind)} ·{' '}
                                            {item.format.toUpperCase()}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {formatDateTime(item.created_at)}
                                            {item.by
                                                ? ` · ${item.by}`
                                                : ''} ·{' '}
                                            {formatBytes(item.size)}
                                        </span>
                                    </span>
                                    <HashText
                                        hash={item.sha256}
                                        label={t('people.reports.file_hash')}
                                    />
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </PeopleFrame>
        </>
    );
}
