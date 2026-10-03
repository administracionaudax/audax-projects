import { Sheet } from 'lucide-react';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import { t } from '@/lib/i18n';
import type { ReportRequestData } from '@/types';

/**
 * «Google Sheets» del menú «Exportar ▾» (D-142). Esqueleto de la entrega 9.2, desactivado: la 9.4
 * lo sustituye con la subida del XLSX a Drive. Firma fija (docs/PLAN-FASE-9.md).
 */
export function SheetsExportItem({ request }: { request: ReportRequestData }) {
    return (
        <DropdownMenuItem disabled data-report-kind={request.kind}>
            <Sheet aria-hidden="true" />
            <span className="grid gap-0.5">
                <span>{t('reports.export.sheets')}</span>
                <span className="text-xs text-muted-foreground">
                    {t('reports.export.soon')}
                </span>
            </span>
        </DropdownMenuItem>
    );
}
