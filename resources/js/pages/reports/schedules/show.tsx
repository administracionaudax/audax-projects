import { Head } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/projects-list/page-header';
import { PageSection } from '@/components/projects-list/page-section';
import { ScheduleReportDialog } from '@/components/reports/delivery/schedule-report-dialog';
import {
    REPORT_VERSIONS,
    supportsVersions,
} from '@/components/reports/report-request';
import {
    describeSchedule,
    reportPeriodOf,
} from '@/components/reports/delivery/schedule-preview';
import {
    DeliveryStatusBadge,
    ScheduleActions,
    ScheduleStatusBadge,
    scheduleState,
} from '@/components/reports/delivery/schedule-status';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { index as reportsIndex } from '@/routes/reports';
import { index, show } from '@/routes/reports/schedules';
import type { ReportScheduleShowProps } from '@/types/report-deliveries';

function Detail({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid gap-1">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="text-sm">{children}</dd>
        </div>
    );
}

/**
 * Detalle de un envío programado (D-141): la frase de cuándo se envía y con qué periodo, los
 * destinatarios (personas y correos externos), el formato, el asunto y el mensaje, el estado y el
 * historial de envíos con su error. Editar, pausar o reanudar, «Enviar ahora» y borrar.
 */
export default function ReportScheduleShow({
    schedule,
}: ReportScheduleShowProps) {
    const [editing, setEditing] = useState(false);
    const period = reportPeriodOf(schedule.request.query);
    const state = scheduleState(schedule);

    return (
        <>
            <Head title={schedule.title} />

            <div className="flex flex-1 flex-col gap-8 p-4 md:p-6">
                <PageHeader
                    title={schedule.title}
                    description={describeSchedule(
                        schedule,
                        schedule.relative_period,
                        period,
                    )}
                    actions={
                        <>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setEditing(true)}
                            >
                                <Pencil aria-hidden="true" />
                                {t('deliveries.actions.edit')}
                            </Button>
                            <ScheduleActions schedule={schedule} />
                        </>
                    }
                />

                <PageSection title={t('deliveries.detail.summary')}>
                    <dl className="grid gap-4 rounded-md border bg-card p-4 sm:grid-cols-2 lg:grid-cols-3">
                        <Detail label={t('deliveries.list.status')}>
                            <span className="flex flex-wrap items-center gap-2">
                                <ScheduleStatusBadge schedule={schedule} />
                                {state === 'paused' &&
                                schedule.paused_reason_label ? (
                                    <span className="text-muted-foreground">
                                        {t('deliveries.detail.paused_because', {
                                            reason: schedule.paused_reason_label,
                                        })}
                                    </span>
                                ) : null}
                            </span>
                        </Detail>
                        <Detail label={t('deliveries.detail.next')}>
                            {schedule.next_run_at
                                ? formatDateTime(schedule.next_run_at)
                                : '—'}
                        </Detail>
                        <Detail label={t('deliveries.detail.last')}>
                            {schedule.last_run_at
                                ? formatDateTime(schedule.last_run_at)
                                : t('deliveries.detail.never')}
                        </Detail>
                        <Detail label={t('deliveries.detail.owner')}>
                            {schedule.owner.name}
                        </Detail>
                        {schedule.version ? (
                            <Detail label={t('deliveries.version.label')}>
                                {t(`reports.version.${schedule.version}`)}
                            </Detail>
                        ) : null}
                        <Detail label={t('deliveries.detail.formats')}>
                            {schedule.formats
                                .map((format) =>
                                    t(`deliveries.formats.${format}`),
                                )
                                .join(' + ')}
                        </Detail>
                        <Detail label={t('deliveries.detail.recipients')}>
                            <ul className="grid gap-0.5">
                                {schedule.recipient_users.map((person) => (
                                    <li key={`u${person.id}`}>{person.name}</li>
                                ))}
                                {schedule.recipient_emails.map((email) => (
                                    <li key={email}>{email}</li>
                                ))}
                            </ul>
                        </Detail>
                        <Detail label={t('deliveries.detail.subject')}>
                            {schedule.subject ??
                                t('deliveries.detail.default_subject')}
                        </Detail>
                        <Detail label={t('deliveries.detail.message')}>
                            <span className="whitespace-pre-line">
                                {schedule.message ??
                                    t('deliveries.detail.none')}
                            </span>
                        </Detail>
                    </dl>
                </PageSection>

                <PageSection title={t('deliveries.history.title')}>
                    {schedule.deliveries.length === 0 ? (
                        <EmptyState title={t('deliveries.history.empty')} />
                    ) : (
                        <Table data-test="schedule-history">
                            <TableHeader>
                                <TableRow>
                                    <TableHead>
                                        {t('deliveries.history.date')}
                                    </TableHead>
                                    <TableHead>
                                        {t('deliveries.history.status')}
                                    </TableHead>
                                    <TableHead>
                                        {t('deliveries.history.recipients')}
                                    </TableHead>
                                    <TableHead>
                                        {t('deliveries.history.formats')}
                                    </TableHead>
                                    <TableHead>
                                        {t('deliveries.history.error')}
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {schedule.deliveries.map((delivery) => (
                                    <TableRow key={delivery.id}>
                                        <TableCell className="tabular-nums">
                                            {formatDateTime(
                                                delivery.sent_at ??
                                                    delivery.created_at,
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <DeliveryStatusBadge
                                                status={delivery.status}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            {delivery.recipient_count === 1
                                                ? t(
                                                      'deliveries.recipients.count_one',
                                                  )
                                                : t(
                                                      'deliveries.recipients.count_other',
                                                      {
                                                          count: delivery.recipient_count,
                                                      },
                                                  )}
                                        </TableCell>
                                        <TableCell>
                                            {delivery.formats
                                                .map((format) =>
                                                    t(
                                                        `deliveries.formats.${format}`,
                                                    ),
                                                )
                                                .join(' + ')}
                                        </TableCell>
                                        <TableCell className="max-w-80 text-muted-foreground">
                                            {delivery.error ?? '—'}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </PageSection>
            </div>

            <ScheduleReportDialog
                open={editing}
                onOpenChange={setEditing}
                request={schedule.request}
                title={schedule.title}
                schedule={schedule}
                versions={
                    supportsVersions(schedule.request.kind)
                        ? REPORT_VERSIONS
                        : undefined
                }
            />
        </>
    );
}

ReportScheduleShow.layout = (props: ReportScheduleShowProps) => ({
    breadcrumbs: [
        { title: t('nav.reports'), href: reportsIndex() },
        { title: t('deliveries.nav.schedules'), href: index() },
        { title: props.schedule.title, href: show(props.schedule.id) },
    ],
});
