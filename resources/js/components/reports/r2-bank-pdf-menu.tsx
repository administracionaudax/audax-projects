import { ChevronDown, FileDown } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAbilities } from '@/hooks/use-auth';
import { t } from '@/lib/i18n';
import { hourBankPdf } from '@/routes/reports';

/**
 * Descarga del PDF de consumo de una bolsa (D-045). El PDF normal es para enviárselo al cliente y
 * nunca lleva importes; quien tiene view-financials puede pedir además el de uso interno
 * (?importes=1), con precio, tarifa e ingreso estimado. Solo se muestra a quien ve el detalle por
 * persona de la bolsa (HourBankPolicy::downloadPdf).
 */
export function R2BankPdfMenu({
    projectId,
    bankId,
    bankName,
    size = 'default',
}: {
    projectId: number;
    bankId: number;
    bankName: string;
    size?: 'default' | 'sm';
}) {
    const can = useAbilities();
    const href = hourBankPdf.url({ project: projectId, hourBank: bankId });
    const label = t('reports_r2.pdf.label');
    const name = t('reports_r2.pdf.name', { name: bankName });

    if (!can.viewFinancials) {
        return (
            <Button variant="outline" size={size} asChild>
                <a
                    href={href}
                    download
                    aria-label={name}
                    title={t('reports_r2.pdf.client_description')}
                >
                    <FileDown aria-hidden="true" />
                    {label}
                </a>
            </Button>
        );
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    size={size}
                    aria-label={name}
                >
                    <FileDown aria-hidden="true" />
                    {label}
                    <ChevronDown aria-hidden="true" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="max-w-80">
                <DropdownMenuItem asChild>
                    <a href={href} download className="grid gap-0.5">
                        <span>{t('reports_r2.pdf.client')}</span>
                        <span className="text-xs text-muted-foreground">
                            {t('reports_r2.pdf.client_description')}
                        </span>
                    </a>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <a
                        href={`${href}?importes=1`}
                        download
                        className="grid gap-0.5"
                    >
                        <span>{t('reports_r2.pdf.internal')}</span>
                        <span className="text-xs text-muted-foreground">
                            {t('reports_r2.pdf.internal_description')}
                        </span>
                    </a>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
