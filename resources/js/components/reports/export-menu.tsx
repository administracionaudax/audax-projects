import { Download } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { t } from '@/lib/i18n';

/**
 * Exportar una tabla o un informe (SPEC §10, D-045): enlaces de descarga normales (no Inertia)
 * a la URL de exportación con `formato=xlsx|csv` y los mismos filtros de la página.
 */
export function ExportMenu({
    href,
    label,
}: {
    /** URL de exportación con su query (sin `formato`). */
    href: string;
    label?: string;
}) {
    const withFormat = (format: 'xlsx' | 'csv') =>
        `${href}${href.includes('?') ? '&' : '?'}formato=${format}`;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button type="button" variant="outline">
                    <Download aria-hidden="true" />
                    {label ?? t('reports.export.label')}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem asChild>
                    <a href={withFormat('xlsx')} download>
                        {t('reports.export.xlsx')}
                    </a>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <a href={withFormat('csv')} download>
                        {t('reports.export.csv')}
                    </a>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
