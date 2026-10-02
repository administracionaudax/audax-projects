import { Link } from '@inertiajs/react';
import { ChevronDown, ChevronRight, Cpu, Trash2 } from 'lucide-react';
import { Fragment, useState } from 'react';
import { Button } from '@/components/ui/button';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { AuditChange, AuditEntry } from '@/types/audit';

/** Antes y después de cada campo de una entrada (tabla con cabeceras). */
export function AuditChanges({ entry, id }: { entry: AuditEntry; id: string }) {
    const onlyValues = entry.changes.every((change) => change.from === null);

    return (
        <div id={id} className="overflow-x-auto" data-test="audit-changes">
            <table className="w-full text-sm">
                <caption className="sr-only">
                    {t('audit.detail.caption', {
                        subject: entry.subject?.label ?? entry.entity.label,
                    })}
                </caption>
                <thead>
                    <tr className="border-b text-left text-muted-foreground">
                        <th scope="col" className="py-1.5 pr-4 font-medium">
                            {t('audit.detail.field')}
                        </th>
                        {onlyValues ? (
                            <th scope="col" className="py-1.5 font-medium">
                                {t('audit.detail.value')}
                            </th>
                        ) : (
                            <>
                                <th
                                    scope="col"
                                    className="py-1.5 pr-4 font-medium"
                                >
                                    {t('audit.detail.before')}
                                </th>
                                <th scope="col" className="py-1.5 font-medium">
                                    {t('audit.detail.after')}
                                </th>
                            </>
                        )}
                    </tr>
                </thead>
                <tbody>
                    {entry.changes.map((change: AuditChange) => (
                        <tr
                            key={change.field}
                            className="border-b align-top last:border-0"
                        >
                            <th
                                scope="row"
                                className="py-1.5 pr-4 text-left font-normal text-muted-foreground"
                            >
                                {change.label}
                            </th>
                            {onlyValues ? (
                                <td className="py-1.5 break-words">
                                    {change.to ?? t('audit.detail.empty')}
                                </td>
                            ) : (
                                <>
                                    <td className="py-1.5 pr-4 break-words">
                                        {change.from ?? t('audit.detail.empty')}
                                    </td>
                                    <td className="py-1.5 break-words">
                                        {change.to ?? t('audit.detail.empty')}
                                    </td>
                                </>
                            )}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function Subject({ entry }: { entry: AuditEntry }) {
    if (entry.subject === null) {
        return <span className="text-muted-foreground">—</span>;
    }

    if (entry.subject.url !== null) {
        return (
            <Link
                href={entry.subject.url}
                className={cn(
                    'rounded-xs break-words text-primary-text underline decoration-primary-text/40 underline-offset-4 hover:decoration-current',
                    FOCUS_RING,
                )}
            >
                {entry.subject.label}
            </Link>
        );
    }

    return (
        <span className="break-words">
            {entry.subject.label}
            {entry.subject.deleted ? (
                <span className="ml-2 inline-flex items-center gap-1 text-xs text-muted-foreground">
                    <Trash2 aria-hidden="true" className="size-3.5" />
                    {t('audit.table.deleted')}
                </span>
            ) : null}
        </span>
    );
}

/**
 * Entradas de la auditoría (D-074): cuándo y quién, qué elemento (con enlace si sigue existiendo)
 * y qué acción, con el detalle del antes y el después desplegable en la fila siguiente. En el
 * móvil caben las tres columnas: la persona va bajo la fecha y la entidad sobre el elemento.
 */
export function AuditTable({ entries }: { entries: AuditEntry[] }) {
    const [open, setOpen] = useState<Set<number>>(() => new Set());

    const toggle = (id: number) =>
        setOpen((current) => {
            const next = new Set(current);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });

    return (
        <div className="overflow-x-auto rounded-md border">
            <table className="w-full text-sm" data-test="audit-table">
                <caption className="sr-only">
                    {t('audit.table.caption')}
                </caption>
                <thead className="bg-muted">
                    <tr className="text-left">
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('audit.table.when')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('audit.table.what')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('audit.table.action')}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {entries.map((entry) => {
                        const expanded = open.has(entry.id);
                        const detailId = `audit-entry-${entry.id}-changes`;

                        return (
                            <Fragment key={entry.id}>
                                <tr
                                    className={cn(
                                        'border-t align-top',
                                        expanded && 'bg-muted/40',
                                    )}
                                    data-test="audit-entry"
                                >
                                    <td className="px-3 py-2.5">
                                        <div className="tabular whitespace-nowrap">
                                            {formatDateTime(entry.created_at)}
                                        </div>
                                        <div className="text-muted-foreground">
                                            {entry.causer ? (
                                                entry.causer.name
                                            ) : (
                                                <span className="inline-flex items-center gap-1">
                                                    <Cpu
                                                        aria-hidden="true"
                                                        className="size-3.5"
                                                    />
                                                    {t('audit.table.system')}
                                                </span>
                                            )}
                                        </div>
                                    </td>
                                    <td className="min-w-40 px-3 py-2.5">
                                        <div className="text-xs text-muted-foreground">
                                            {entry.entity.label}
                                        </div>
                                        <Subject entry={entry} />
                                    </td>
                                    <td className="px-3 py-2.5">
                                        <div>{entry.event_label}</div>
                                        {entry.changes.length > 0 ? (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                className="-ml-2 h-8 px-2 text-primary-text"
                                                aria-expanded={expanded}
                                                aria-controls={
                                                    expanded
                                                        ? detailId
                                                        : undefined
                                                }
                                                onClick={() => toggle(entry.id)}
                                            >
                                                {expanded ? (
                                                    <ChevronDown aria-hidden="true" />
                                                ) : (
                                                    <ChevronRight aria-hidden="true" />
                                                )}
                                                {t(
                                                    expanded
                                                        ? 'audit.table.hide_changes'
                                                        : 'audit.table.show_changes',
                                                    {
                                                        count: entry.changes
                                                            .length,
                                                    },
                                                )}
                                            </Button>
                                        ) : null}
                                    </td>
                                </tr>
                                {expanded ? (
                                    <tr className="bg-muted/40">
                                        <td
                                            colSpan={3}
                                            className="px-3 pt-0 pb-3"
                                        >
                                            <AuditChanges
                                                entry={entry}
                                                id={detailId}
                                            />
                                        </td>
                                    </tr>
                                ) : null}
                            </Fragment>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}
