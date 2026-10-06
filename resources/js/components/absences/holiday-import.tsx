import { router } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    CircleAlert,
    CirclePlus,
    Copy,
    FileUp,
    Upload,
    Equal,
} from 'lucide-react';
import { useId, useRef, useState } from 'react';
import type {
    HolidayImportPreview,
    HolidayImportStatus,
    HolidaysPageProps,
} from '@/components/absences/types';
import { describedBy } from '@/components/admin/field';
import { toastVisitErrors } from '@/components/admin/visit-errors';
import InputError from '@/components/input-error';
import { StatusBadge } from '@/components/styleguide/status-badges';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { importMethod, preview } from '@/routes/admin/holidays';

type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'danger';

const STATUS: Record<HolidayImportStatus, { tone: Tone; icon: LucideIcon }> = {
    new: { tone: 'success', icon: CirclePlus },
    existing: { tone: 'neutral', icon: Equal },
    duplicate: { tone: 'warning', icon: Copy },
    error: { tone: 'danger', icon: CircleAlert },
};

const ORDER: HolidayImportStatus[] = ['new', 'existing', 'duplicate', 'error'];

function isPreview(value: unknown): value is HolidayImportPreview {
    return (
        typeof value === 'object' &&
        value !== null &&
        Array.isArray((value as { rows?: unknown }).rows)
    );
}

export function ImportStatusBadge({ status }: { status: HolidayImportStatus }) {
    const meta = STATUS[status];

    return (
        <StatusBadge tone={meta.tone} icon={meta.icon}>
            {t(`holidays.import.status.${status}`)}
        </StatusBadge>
    );
}

/**
 * Importar festivos autonómicos y locales (D-050): se sube un .ics o un CSV, el servidor devuelve
 * la vista previa (flash `holiday_import`: cada línea con su estado, su error y su aviso) y se
 * confirman los nuevos. No se guarda nada hasta confirmar. Los eventos del .ics que se repiten
 * cada año se toman en `year` (el año de la página); uno de varios días da una fila por día.
 */
export function HolidayImport({
    limits,
    year,
    initialPreview = null,
}: {
    limits: HolidaysPageProps['limits'];
    year: number;
    initialPreview?: HolidayImportPreview | null;
}) {
    const id = useId();
    const fileInput = useRef<HTMLInputElement>(null);
    const [file, setFile] = useState<File | null>(null);
    const [result, setResult] = useState<HolidayImportPreview | null>(
        initialPreview,
    );
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);
    const newRows = (result?.rows ?? []).filter(
        (row) => row.status === 'new' && row.date !== null && row.name !== null,
    );

    const requestPreview = (event: React.FormEvent) => {
        event.preventDefault();

        if (file === null) {
            setError(t('holidays.import.pick_file'));

            return;
        }

        router.post(
            preview.url(),
            { file, year },
            {
                forceFormData: true,
                preserveScroll: true,
                preserveState: true,
                onStart: () => {
                    setProcessing(true);
                    setError(undefined);
                },
                onFinish: () => setProcessing(false),
                onFlash: (flash) => {
                    const data = (flash as Record<string, unknown>)
                        .holiday_import;

                    if (isPreview(data)) {
                        setResult(data);
                    }
                },
                onError: (errors) => {
                    setResult(null);
                    setError(
                        typeof errors.file === 'string'
                            ? errors.file
                            : t('holidays.import.failed'),
                    );
                },
            },
        );
    };

    const discard = () => {
        setResult(null);
        setFile(null);
        setError(undefined);

        if (fileInput.current) {
            fileInput.current.value = '';
        }
    };

    const confirm = () => {
        router.post(
            importMethod.url(),
            {
                rows: newRows.map((row) => ({
                    date: row.date,
                    name: row.name,
                })),
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => discard(),
                onError: toastVisitErrors,
            },
        );
    };

    return (
        <section
            aria-labelledby={`${id}-heading`}
            className="grid min-w-0 gap-4 rounded-md border p-4"
            data-test="holiday-import"
        >
            <div className="space-y-1">
                <h2
                    id={`${id}-heading`}
                    className="flex items-center gap-2 text-lg"
                >
                    <FileUp
                        aria-hidden="true"
                        className="size-5 text-muted-foreground"
                        strokeWidth={1.5}
                    />
                    {t('holidays.import.heading')}
                </h2>
                <p className="text-sm text-muted-foreground">
                    {t('holidays.import.description')}
                </p>
            </div>

            <form onSubmit={requestPreview} className="grid gap-2" noValidate>
                <Label htmlFor={`${id}-file`}>
                    {t('holidays.import.file')}
                </Label>
                {/* Caja y botón en la misma fila; la ayuda y el error debajo, a todo el ancho (antes el
                    botón se alineaba con un margen fijo que no cuadraba con ayudas de varias líneas). */}
                <div className="flex flex-col gap-2 sm:flex-row">
                    <Input
                        ref={fileInput}
                        id={`${id}-file`}
                        type="file"
                        accept=".ics,.csv,.txt,text/calendar,text/csv,text/plain"
                        onChange={(event) => {
                            setFile(event.target.files?.[0] ?? null);
                            setError(undefined);
                        }}
                        aria-invalid={error ? true : undefined}
                        aria-describedby={describedBy(`${id}-file`, {
                            help: true,
                            error,
                        })}
                    />
                    <Button
                        type="submit"
                        variant="outline"
                        disabled={processing}
                        className="shrink-0"
                    >
                        {processing ? (
                            <Spinner />
                        ) : (
                            <Upload aria-hidden="true" />
                        )}
                        {t('holidays.import.preview')}
                    </Button>
                </div>
                <p
                    id={`${id}-file-help`}
                    className="text-sm text-muted-foreground"
                >
                    {t('holidays.import.file_help', {
                        kb: limits.max_kilobytes,
                        rows: limits.max_rows,
                    })}{' '}
                    {t('holidays.import.recurring_help', { year })}
                </p>
                <InputError id={`${id}-file-error`} message={error} />
            </form>

            {result ? (
                <div className="grid min-w-0 gap-3" data-test="holiday-preview">
                    <h3 className="text-base font-medium">
                        {t('holidays.import.preview_heading', {
                            file: result.file_name,
                        })}
                    </h3>
                    <ul className="flex flex-wrap gap-2" aria-live="polite">
                        {ORDER.map((status) => (
                            <li key={status}>
                                <StatusBadge
                                    tone={STATUS[status].tone}
                                    icon={STATUS[status].icon}
                                >
                                    {t(`holidays.import.count.${status}`, {
                                        count: result.counts[status] ?? 0,
                                    })}
                                </StatusBadge>
                            </li>
                        ))}
                    </ul>

                    <div
                        className={cn(
                            'max-h-96 overflow-auto rounded-md border',
                            FOCUS_RING,
                        )}
                        role="region"
                        aria-label={t('holidays.import.table_label')}
                        tabIndex={0}
                    >
                        <table className="w-full min-w-[36rem] text-sm">
                            <caption className="sr-only">
                                {t('holidays.import.table_label')}
                            </caption>
                            <thead className="sticky top-0 bg-background">
                                <tr className="border-b text-left">
                                    <th
                                        scope="col"
                                        className="px-3 py-2 text-right font-medium"
                                    >
                                        {t('holidays.import.column.line')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-3 py-2 font-medium"
                                    >
                                        {t('holidays.import.column.date')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-3 py-2 font-medium"
                                    >
                                        {t('holidays.import.column.name')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-3 py-2 font-medium"
                                    >
                                        {t('holidays.import.column.status')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {result.rows.map((row, index) => (
                                    <tr
                                        // Un evento de varios días da varias filas con su misma línea.
                                        key={`${row.line}-${index}`}
                                        className="border-b align-top last:border-b-0"
                                        data-test="holiday-preview-row"
                                    >
                                        <td className="tabular px-3 py-2 text-right text-muted-foreground">
                                            {row.line}
                                        </td>
                                        <td className="tabular px-3 py-2 whitespace-nowrap">
                                            {row.date
                                                ? formatDate(row.date)
                                                : '—'}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.name ?? '—'}
                                        </td>
                                        <td className="px-3 py-2">
                                            <ImportStatusBadge
                                                status={row.status}
                                            />
                                            {row.message ? (
                                                <span className="mt-1 block text-xs text-muted-foreground">
                                                    {row.message}
                                                </span>
                                            ) : null}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {newRows.length > 0 ? (
                            <Button
                                type="button"
                                disabled={processing}
                                onClick={confirm}
                            >
                                {processing ? (
                                    <Spinner />
                                ) : (
                                    <CirclePlus aria-hidden="true" />
                                )}
                                {newRows.length === 1
                                    ? t('holidays.import.confirm_one')
                                    : t('holidays.import.confirm_many', {
                                          count: newRows.length,
                                      })}
                            </Button>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                {t('holidays.import.nothing_new')}
                            </p>
                        )}
                        <Button
                            type="button"
                            variant="ghost"
                            disabled={processing}
                            onClick={discard}
                        >
                            {t('holidays.import.discard')}
                        </Button>
                    </div>
                </div>
            ) : null}
        </section>
    );
}
