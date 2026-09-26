import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';

/** Confirmación para acciones que no se pueden deshacer. */
export function ConfirmDialog({
    trigger,
    title,
    description,
    confirmLabel,
    onConfirm,
    processing = false,
    destructive = true,
    open,
    onOpenChange,
    onCloseAutoFocus,
}: {
    trigger: ReactNode;
    title: string;
    description: string;
    confirmLabel: string;
    onConfirm: () => void;
    processing?: boolean;
    destructive?: boolean;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
    /** Dónde dejar el foco al cerrar (p. ej. si el disparador desaparece con la acción). */
    onCloseAutoFocus?: (event: Event) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent onCloseAutoFocus={onCloseAutoFocus}>
                <DialogTitle>{title}</DialogTitle>
                <DialogDescription>{description}</DialogDescription>
                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary" disabled={processing}>
                            {t('common.cancel')}
                        </Button>
                    </DialogClose>
                    <Button
                        variant={destructive ? 'destructive' : 'default'}
                        disabled={processing}
                        onClick={onConfirm}
                    >
                        {processing && <Spinner />}
                        {confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
