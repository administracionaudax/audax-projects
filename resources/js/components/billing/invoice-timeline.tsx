import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    Ban,
    CalendarClock,
    CircleDollarSign,
    FileText,
    Link2,
    PencilLine,
    RefreshCw,
    Undo2,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { FOCUS_RING } from '@/lib/focus-ring';
import {
    formatCurrency,
    formatDate,
    formatDateTime,
    LOCALE,
    TIME_ZONE,
} from '@/lib/format';
import { t } from '@/lib/i18n';
import { tCount } from '@/lib/people';
import { cn } from '@/lib/utils';
import type { HoldedInvoiceDetail } from '@/types';
import { daysBetween } from './billing-time';
import { invoiceUrl } from './invoice-table';

type Tone = 'neutral' | 'danger' | 'success' | 'muted';

export type TimelineEvent = {
    key: string;
    /** Fecha AAAA-MM-DD para ordenar; null = al final (lo que no tiene fecha en Holded). */
    date: string | null;
    /** Lo que se enseña como fecha (un instante lleva su hora). */
    when: string;
    icon: LucideIcon;
    tone: Tone;
    content: ReactNode;
};

/** Fecha local (Madrid) de un instante. */
function localDate(iso: string): string {
    const parts = new Intl.DateTimeFormat(LOCALE, {
        timeZone: TIME_ZONE,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).formatToParts(new Date(iso));
    const get = (type: string) =>
        parts.find((part) => part.type === type)?.value ?? '';

    return `${get('year')}-${get('month')}-${get('day')}`;
}

function DocLink({ id, number }: { id: number; number: string | null }) {
    return (
        <Link
            href={invoiceUrl(id)}
            className={cn('rounded-md text-primary-text underline', FOCUS_RING)}
        >
            {number ?? t('billing.invoice.draft_number')}
        </Link>
    );
}

/**
 * Los hechos de una factura en orden (D-408): emitida, vencimiento, cada cobro, rectificativas,
 * anulada, los enlaces hechos a mano (quién y cuándo) y la última lectura de Holded. Todo sale de
 * lo que ya se lee de Holded; es solo lectura.
 */
export function invoiceTimeline(
    invoice: HoldedInvoiceDetail,
    today: string,
): TimelineEvent[] {
    const events: TimelineEvent[] = [];
    const draft = invoice.is_draft || invoice.collection_status === 'draft';

    events.push({
        key: 'issued',
        date: invoice.issued_on,
        when: formatDate(invoice.issued_on),
        icon: draft ? PencilLine : FileText,
        tone: 'neutral',
        content: t(
            draft
                ? 'billing.timeline.draft'
                : invoice.kind === 'credit_note'
                  ? 'billing.timeline.issued_credit'
                  : 'billing.timeline.issued',
            { total: formatCurrency(invoice.total) },
        ),
    });

    if (invoice.rectified) {
        events.push({
            key: 'rectifies',
            date: invoice.issued_on,
            when: formatDate(invoice.issued_on),
            icon: Undo2,
            tone: 'neutral',
            content: (
                <>
                    {t('billing.invoice.rectifies')}{' '}
                    <DocLink
                        id={invoice.rectified.id}
                        number={invoice.rectified.number}
                    />
                </>
            ),
        });
    }

    if (invoice.due_on && !draft) {
        const late = daysBetween(invoice.due_on, today);
        const pending =
            Number(invoice.pending_total) > 0 &&
            invoice.collection_status !== 'cancelled';

        events.push({
            key: 'due',
            date: invoice.due_on,
            when: formatDate(invoice.due_on),
            icon: CalendarClock,
            tone:
                pending && late > 0 ? 'danger' : late < 0 ? 'muted' : 'neutral',
            content: pending
                ? late > 0
                    ? tCount('billing.timeline.due_overdue', late)
                    : late === 0
                      ? t('billing.timeline.due_today')
                      : tCount('billing.timeline.due_in', -late)
                : t('billing.timeline.due'),
        });
    }

    invoice.payments.forEach((payment) => {
        events.push({
            key: `payment-${payment.id}`,
            date: payment.paid_on,
            when: formatDate(payment.paid_on),
            icon: CircleDollarSign,
            tone: 'success',
            content: t(
                payment.method
                    ? 'billing.timeline.payment_method'
                    : 'billing.timeline.payment',
                {
                    amount: formatCurrency(payment.amount),
                    method: payment.method ?? '',
                },
            ),
        });
    });

    invoice.rectifications.forEach((credit) => {
        events.push({
            key: `credit-${credit.id}`,
            date: credit.issued_on,
            when: formatDate(credit.issued_on),
            icon: Undo2,
            tone: 'neutral',
            content: (
                <>
                    {t('billing.invoice.rectified_by')}{' '}
                    <DocLink id={credit.id} number={credit.number} />{' '}
                    <span className="tabular text-muted-foreground">
                        ({formatCurrency(credit.subtotal)})
                    </span>
                </>
            ),
        });
    });

    invoice.links
        .filter((link) => link.method === 'manual' && link.created_at)
        .forEach((link) => {
            const at = link.created_at as string;

            events.push({
                key: `link-${link.id}`,
                date: localDate(at),
                when: formatDateTime(at),
                icon: Link2,
                tone: 'neutral',
                content: t(
                    link.created_by
                        ? 'billing.timeline.linked_by'
                        : 'billing.timeline.linked',
                    {
                        target: `${link.project?.code ?? ''}${link.bank ? ` · ${link.bank.name}` : ''}`,
                        person: link.created_by ?? '',
                    },
                ),
            });
        });

    if (invoice.collection_status === 'cancelled') {
        events.push({
            key: 'cancelled',
            date: null,
            when: '',
            icon: Ban,
            tone: 'muted',
            content: t('billing.timeline.cancelled'),
        });
    }

    if (invoice.synced_at) {
        events.push({
            key: 'synced',
            date: null,
            when: formatDateTime(invoice.synced_at),
            icon: RefreshCw,
            tone: 'muted',
            content: t('billing.timeline.synced'),
        });
    }

    // Por fecha (estable: a igualdad, el orden de arriba); lo que no tiene fecha, al final.
    return events
        .map((event, index) => ({ event, index }))
        .sort((a, b) => {
            if (a.event.date === b.event.date) {
                return a.index - b.index;
            }

            if (a.event.date === null) {
                return 1;
            }

            if (b.event.date === null) {
                return -1;
            }

            return a.event.date < b.event.date ? -1 : 1;
        })
        .map(({ event }) => event);
}

const DOT: Record<Tone, string> = {
    neutral: 'text-foreground',
    danger: 'text-danger',
    success: 'text-success',
    muted: 'text-muted-foreground',
};

/** La línea de tiempo de la ficha: una lista ordenada con su icono, fecha y texto. */
export function InvoiceTimeline({ events }: { events: TimelineEvent[] }) {
    return (
        <ol className="grid gap-0" data-test="invoice-timeline">
            {events.map((event, index) => (
                <li
                    key={event.key}
                    className="grid grid-cols-[1.5rem_minmax(0,1fr)] gap-x-3"
                >
                    <span className="flex flex-col items-center">
                        <span className="flex size-6 items-center justify-center rounded-full border bg-card">
                            <event.icon
                                aria-hidden="true"
                                strokeWidth={1.5}
                                className={cn('size-3.5', DOT[event.tone])}
                            />
                        </span>
                        {index < events.length - 1 ? (
                            <span
                                aria-hidden="true"
                                className="w-px flex-1 bg-border"
                            />
                        ) : null}
                    </span>
                    <div className="min-w-0 pb-4 text-sm">
                        <p
                            className={cn(
                                event.tone === 'danger' && 'text-danger',
                                event.tone === 'muted' &&
                                    'text-muted-foreground',
                            )}
                        >
                            {event.content}
                        </p>
                        {event.when ? (
                            <p className="tabular text-xs text-muted-foreground">
                                {event.when}
                            </p>
                        ) : null}
                    </div>
                </li>
            ))}
        </ol>
    );
}
