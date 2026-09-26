import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { t } from '@/lib/i18n';
import type { TimeEntry } from '@/types';

export type TimeEntryDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Tarea preseleccionada (desde el panel de tarea o Mis tareas). */
    task?: { id: number; title: string; project_id: number } | null;
    /** Entrada a editar (si no, se crea una nueva). */
    entry?: TimeEntry | null;
    /** Fecha propuesta "YYYY-MM-DD" (por defecto, hoy en Madrid). */
    date?: string;
    /** Imputar en nombre de otra persona (gestores, responsables y admin, SPEC §7). */
    userId?: number;
};

/**
 * Diálogo de entrada manual de horas (SPEC §7): tarea, fecha, duración y descripción.
 *
 * Contrato: lo usan el panel de tarea (Agente C) y la hoja semanal. La implementación es del
 * área Horas (Agente D), que sustituye este esqueleto conservando las props.
 */
export function TimeEntryDialog({
    open,
    onOpenChange,
    task,
}: TimeEntryDialogProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('time_entry_dialog.title')}</DialogTitle>
                    <DialogDescription>{task?.title ?? ''}</DialogDescription>
                </DialogHeader>
            </DialogContent>
        </Dialog>
    );
}
