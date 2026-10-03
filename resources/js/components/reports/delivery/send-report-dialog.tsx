import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { t } from '@/lib/i18n';
import type { ReportRequestData } from '@/types';

/**
 * «Enviar por correo…» (D-141). Esqueleto de la entrega 9.2: la 9.3 lo sustituye con el formulario
 * de destinatarios y el envío. Firma fija (docs/PLAN-FASE-9.md).
 */
export function SendReportDialog({
    open,
    onOpenChange,
    title,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    request: ReportRequestData;
    title: string;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('reports.export.send')}</DialogTitle>
                    <DialogDescription>{title}</DialogDescription>
                </DialogHeader>
                <p className="text-sm text-muted-foreground">
                    {t('reports.export.soon')}.{' '}
                    {t('reports.export.soon_description')}
                </p>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button type="button" variant="outline">
                            {t('reports.export.close')}
                        </Button>
                    </DialogClose>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
