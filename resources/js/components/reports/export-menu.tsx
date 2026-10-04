import {
    ChevronDown,
    Download,
    FileSpreadsheet,
    FileText,
    Mail,
    Printer,
    CalendarClock,
} from 'lucide-react';
import { useState } from 'react';
import { ScheduleReportDialog } from '@/components/reports/delivery/schedule-report-dialog';
import { SendReportDialog } from '@/components/reports/delivery/send-report-dialog';
import { SheetsExportItem } from '@/components/reports/delivery/sheets-export-item';
import { stripKeywords } from '@/components/keyword-text';
import { reportRequestUrl } from '@/components/reports/report-request';
import type { ReportFormat } from '@/components/reports/report-request';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { t } from '@/lib/i18n';
import type { ReportRequestData } from '@/types';

/**
 * Menú «Exportar ▾» de un informe (Fase 9, D-139 y D-140), con los filtros de la página
 * (`request`, la prop `report_request` del controlador):
 * - Excel, CSV y PDF descargan (enlaces normales, no Inertia: ?formato=xlsx|csv|pdf),
 * - Google Sheets sube el Excel a Drive (9.4),
 * - Imprimir abre el mismo HTML del PDF en una pestaña con el diálogo de impresión,
 * - «Enviar por correo…» y «Programar envío…» abren sus diálogos (9.3).
 * Con `scope="table"` es el menú de una tabla del informe (su `tabla=`): solo Excel, CSV y Google
 * Sheets, que son los formatos de una tabla; el PDF y el resto van en el menú del informe.
 */
export function ExportMenu({
    request,
    title,
    label,
    scope = 'report',
    size = 'default',
}: {
    request: ReportRequestData;
    /** Título legible del informe (para los diálogos de envío). */
    title: string;
    label?: string;
    scope?: 'report' | 'table';
    size?: 'default' | 'sm';
}) {
    const [dialog, setDialog] = useState<'send' | 'schedule' | null>(null);
    const url = (format: ReportFormat) => reportRequestUrl(request, format);
    const full = scope === 'report';

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        type="button"
                        variant="outline"
                        size={size}
                        data-test={full ? 'export-menu' : 'export-table-menu'}
                    >
                        <Download aria-hidden="true" />
                        {label ?? t('reports.export.label')}
                        <ChevronDown aria-hidden="true" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="min-w-56">
                    <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">
                        {t(
                            full
                                ? 'reports.export.report_hint'
                                : 'reports.export.table_hint',
                        )}
                    </DropdownMenuLabel>
                    <DropdownMenuItem asChild>
                        <a href={url('xlsx')} download>
                            <FileSpreadsheet aria-hidden="true" />
                            {t('reports.export.xlsx')}
                        </a>
                    </DropdownMenuItem>
                    <DropdownMenuItem asChild>
                        <a href={url('csv')} download>
                            <FileSpreadsheet aria-hidden="true" />
                            {t('reports.export.csv')}
                        </a>
                    </DropdownMenuItem>
                    {full ? (
                        <DropdownMenuItem asChild>
                            <a href={url('pdf')} download>
                                <FileText aria-hidden="true" />
                                {t('reports.export.pdf')}
                            </a>
                        </DropdownMenuItem>
                    ) : null}
                    <SheetsExportItem request={request} />
                    {full ? (
                        <>
                            <DropdownMenuItem asChild>
                                <a
                                    href={url('imprimir')}
                                    target="_blank"
                                    rel="noopener"
                                >
                                    <Printer aria-hidden="true" />
                                    {t('reports.export.print')}
                                </a>
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                onSelect={() => setDialog('send')}
                            >
                                <Mail aria-hidden="true" />
                                {t('reports.export.send')}
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                onSelect={() => setDialog('schedule')}
                            >
                                <CalendarClock aria-hidden="true" />
                                {t('reports.export.schedule')}
                            </DropdownMenuItem>
                        </>
                    ) : null}
                </DropdownMenuContent>
            </DropdownMenu>

            {full ? (
                <>
                    <SendReportDialog
                        open={dialog === 'send'}
                        onOpenChange={(open) => setDialog(open ? 'send' : null)}
                        request={request}
                        title={stripKeywords(title)}
                    />
                    <ScheduleReportDialog
                        open={dialog === 'schedule'}
                        onOpenChange={(open) =>
                            setDialog(open ? 'schedule' : null)
                        }
                        request={request}
                        title={stripKeywords(title)}
                    />
                </>
            ) : null}
        </>
    );
}
