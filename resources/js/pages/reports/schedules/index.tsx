import { Head, Link } from '@inertiajs/react';
import { CalendarClock, ChevronDown, Plus } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/projects-list/page-header';
import { ScheduleReportDialog } from '@/components/reports/delivery/schedule-report-dialog';
import { describeWhen } from '@/components/reports/delivery/schedule-preview';
import {
    ScheduleActions,
    ScheduleStatusBadge,
} from '@/components/reports/delivery/schedule-status';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as reportsIndex } from '@/routes/reports';
import { index, show } from '@/routes/reports/schedules';
import type {
    NewReportOption,
    ReportScheduleRow,
    ReportSchedulesIndexProps,
} from '@/types/report-deliveries';

function recipientsLabel(row: ReportScheduleRow): string {
    const count =
        row.recipient_count === 1
            ? t('deliveries.recipients.count_one')
            : t('deliveries.recipients.count_other', {
                  count: row.recipient_count,
              });

    if (row.external_count === 0) {
        return count;
    }

    return `${count} · ${
        row.external_count === 1
            ? t('deliveries.list.external_one')
            : t('deliveries.list.external_other', { count: row.external_count })
    }`;
}

/**
 * Envíos programados (/informes/envios, D-141): los míos (el admin, todos) con su frecuencia, sus
 * destinatarios, el próximo envío y su estado; pausar, reanudar, «Enviar ahora» y borrar desde la
 * lista, y editar desde el detalle. «Programar un envío» ofrece los informes de siempre (el
 * personal, el detallado y el de dirección); el resto se programa desde «Exportar ▾».
 */
export default function ReportSchedulesIndex({
    schedules,
    sees_all,
    new_reports,
}: ReportSchedulesIndexProps) {
    const [creating, setCreating] = useState<NewReportOption | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);

    const start = (report: NewReportOption) => {
        setCreating(report);
        setDialogOpen(true);
    };

    // Sin modal: el menú se cierra sin dejar el foco atrapado al abrir el diálogo.
    const newMenu = (
        <DropdownMenu modal={false}>
            <DropdownMenuTrigger asChild>
                <Button type="button" data-test="schedule-new">
                    <Plus aria-hidden="true" />
                    {t('deliveries.list.new')}
                    <ChevronDown aria-hidden="true" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-72">
                <DropdownMenuLabel className="font-normal text-muted-foreground">
                    {t('deliveries.list.new_hint')}
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                {new_reports.map((report) => (
                    <DropdownMenuItem
                        key={report.kind}
                        onSelect={() => start(report)}
                    >
                        {report.title}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );

    return (
        <>
            <Head title={t('deliveries.list.title')} />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('deliveries.list.title')}
                    description={
                        sees_all
                            ? t('deliveries.list.description_all')
                            : t('deliveries.list.description')
                    }
                    actions={newMenu}
                />

                {schedules.length === 0 ? (
                    <EmptyState
                        icon={CalendarClock}
                        title={t('deliveries.list.empty_title')}
                        description={t('deliveries.list.empty_description')}
                    />
                ) : (
                    <Table data-test="schedules-table">
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {t('deliveries.list.report')}
                                </TableHead>
                                {sees_all ? (
                                    <TableHead>
                                        {t('deliveries.list.owner')}
                                    </TableHead>
                                ) : null}
                                <TableHead>
                                    {t('deliveries.list.when')}
                                </TableHead>
                                <TableHead>
                                    {t('deliveries.list.recipients')}
                                </TableHead>
                                <TableHead>
                                    {t('deliveries.list.next')}
                                </TableHead>
                                <TableHead>
                                    {t('deliveries.list.status')}
                                </TableHead>
                                <TableHead>
                                    <span className="sr-only">
                                        {t('deliveries.list.actions')}
                                    </span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {schedules.map((schedule) => (
                                <TableRow
                                    key={schedule.id}
                                    data-test="schedule-row"
                                >
                                    <TableCell className="max-w-72">
                                        <Link
                                            href={show(schedule.id)}
                                            className={cn(
                                                'font-medium text-primary-text hover:underline',
                                                FOCUS_RING,
                                            )}
                                        >
                                            {schedule.title}
                                        </Link>
                                        {schedule.version === 'cliente' ? (
                                            <Badge
                                                variant="outline"
                                                className="ml-2"
                                            >
                                                {t('reports.version.cliente')}
                                            </Badge>
                                        ) : null}
                                    </TableCell>
                                    {sees_all ? (
                                        <TableCell>
                                            {schedule.owner.name}
                                        </TableCell>
                                    ) : null}
                                    <TableCell>
                                        {describeWhen(schedule)}
                                    </TableCell>
                                    <TableCell>
                                        {recipientsLabel(schedule)}
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {schedule.next_run_at
                                            ? formatDateTime(
                                                  schedule.next_run_at,
                                              )
                                            : '—'}
                                    </TableCell>
                                    <TableCell>
                                        <ScheduleStatusBadge
                                            schedule={schedule}
                                        />
                                    </TableCell>
                                    <TableCell>
                                        <ScheduleActions
                                            schedule={schedule}
                                            compact
                                        />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </div>

            {creating ? (
                <ScheduleReportDialog
                    open={dialogOpen}
                    onOpenChange={setDialogOpen}
                    request={{
                        kind: creating.kind,
                        route_params: creating.route_params,
                        query: creating.query,
                    }}
                    title={creating.title}
                />
            ) : null}
        </>
    );
}

ReportSchedulesIndex.layout = {
    breadcrumbs: [
        { title: t('nav.reports'), href: reportsIndex() },
        { title: t('deliveries.nav.schedules'), href: index() },
    ],
};
