import { useForm } from '@inertiajs/react';
import { CalendarClock } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { useId, useState } from 'react';
import { DatePicker } from '@/components/domain/date-picker';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { SalesDocumentDetail } from '@/types';

/** El día siguiente a uno dado (AAAA-MM-DD), para el mínimo y el valor por defecto. */
export function nextDay(date: string): string {
    const [year, month, day] = date.split('-').map(Number);
    const next = new Date(Date.UTC(year, month - 1, day + 1));

    return next.toISOString().slice(0, 10);
}

/**
 * Programar la emisión (PLAN-EMISION §6.2, «Programar»; E3): una fecha posterior a hoy y, si se
 * quiere, «Enviar al emitir». Hasta ese día sigue siendo un borrador; esa mañana se emite sola en
 * nombre de quien la programó (y se envía a los emails de facturación del cliente). En modo de prueba
 * no se emite sola: el diálogo lo avisa.
 */
export function ScheduleDialog({
    document,
    today,
    autoActive,
    trigger,
    defaultOpen = false,
}: {
    document: SalesDocumentDetail;
    today: string;
    autoActive: boolean;
    trigger: ReactNode;
    defaultOpen?: boolean;
}) {
    const id = useId();
    const [open, setOpen] = useState(defaultOpen);
    const form = useForm({
        scheduled_for: document.scheduled?.for ?? nextDay(today),
        scheduled_send: document.scheduled?.send ?? false,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/facturacion/documentos/${document.id}/programar`, {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <DialogTitle>
                    {document.scheduled
                        ? t('invoicing.schedule.title_change')
                        : t('invoicing.schedule.title')}
                </DialogTitle>
                <DialogDescription>
                    {t('invoicing.schedule.description')}
                </DialogDescription>
                <form
                    onSubmit={submit}
                    className="grid gap-4"
                    data-test="schedule-form"
                >
                    <div className="grid gap-1.5">
                        <Label htmlFor={`${id}-date`}>
                            {t('invoicing.schedule.date')}
                        </Label>
                        <DatePicker
                            id={`${id}-date`}
                            value={form.data.scheduled_for}
                            min={nextDay(today)}
                            clearable={false}
                            invalid={Boolean(form.errors.scheduled_for)}
                            onChange={(value) =>
                                form.setData(
                                    'scheduled_for',
                                    value ?? nextDay(today),
                                )
                            }
                        />
                        <InputError message={form.errors.scheduled_for} />
                    </div>
                    <div className="flex items-start gap-2">
                        <Checkbox
                            id={`${id}-send`}
                            checked={form.data.scheduled_send}
                            onCheckedChange={(value) =>
                                form.setData('scheduled_send', value === true)
                            }
                            className="mt-0.5"
                        />
                        <Label
                            htmlFor={`${id}-send`}
                            className="grid gap-0.5 font-normal"
                        >
                            <span>{t('invoicing.schedule.send')}</span>
                            <span className="text-xs text-muted-foreground">
                                {t('invoicing.schedule.send_hint')}
                            </span>
                        </Label>
                    </div>
                    <p className="rounded-md bg-info-soft px-3 py-2 text-sm">
                        {t('invoicing.schedule.summary', {
                            date: formatDate(form.data.scheduled_for),
                        })}
                    </p>
                    {autoActive ? null : (
                        <p
                            className="rounded-md bg-warning-soft px-3 py-2 text-sm"
                            data-test="schedule-test-mode"
                        >
                            {t('invoicing.schedule.test_mode')}
                        </p>
                    )}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            {t('common.cancel')}
                        </Button>
                        <Button
                            type="submit"
                            disabled={form.processing}
                            data-test="schedule-submit"
                        >
                            {form.processing ? (
                                <Spinner />
                            ) : (
                                <CalendarClock aria-hidden="true" />
                            )}
                            {t('invoicing.schedule.submit')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
