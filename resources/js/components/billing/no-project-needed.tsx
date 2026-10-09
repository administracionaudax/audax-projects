import { router } from '@inertiajs/react';
import { CircleSlash, Undo2 } from 'lucide-react';
import { useId, useState } from 'react';
import { describedBy, Field } from '@/components/admin/field';
import { PageSection } from '@/components/projects-list/page-section';
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
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';

/** Máximo del motivo (como HoldedInvoice::NO_PROJECT_NOTE_MAX). */
export const NO_PROJECT_NOTE_MAX = 500;

export const noProjectUrl = (invoiceId: number) =>
    `/facturacion/facturas/${invoiceId}/sin-proyecto`;

/** Cómo se nombra la factura en las etiquetas (los borradores no tienen número). */
export function invoiceLabel(invoice: { number: string | null }): string {
    return invoice.number ?? t('billing.invoice.draft_number');
}

/**
 * «No necesita proyecto» (D-431): gastos repercutidos o una factura suelta. El botón abre un diálogo
 * con el motivo (opcional); al marcarla deja de salir en «Sin proyecto» y en «Por revisar». Se
 * deshace con «Necesita proyecto» (en la ficha) o con «Deshacer» en la bandeja.
 */
export function MarkNoProjectButton({
    invoice,
    variant = 'ghost',
    compact = false,
}: {
    invoice: { id: number; number: string | null };
    variant?: 'ghost' | 'outline';
    /** En las filas estrechas, solo el icono por debajo de lg. */
    compact?: boolean;
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const [note, setNote] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);
    const label = invoiceLabel(invoice);

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        router.post(
            noProjectUrl(invoice.id),
            { note: note.trim() === '' ? null : note.trim() },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (errors) => setError(errors.note),
                onSuccess: () => {
                    setOpen(false);
                    setNote('');
                },
            },
        );
    };

    return (
        <>
            <Button
                type="button"
                size="sm"
                variant={variant}
                onClick={() => {
                    setError(undefined);
                    setOpen(true);
                }}
                aria-label={t('billing.no_project.action_for', {
                    invoice: label,
                })}
                data-test="no-project-mark"
            >
                <CircleSlash aria-hidden="true" />
                <span className={compact ? 'sm:sr-only lg:not-sr-only' : ''}>
                    {t('billing.no_project.action')}
                </span>
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent data-test="no-project-dialog">
                    <form onSubmit={submit} className="grid gap-5" noValidate>
                        <DialogHeader>
                            <DialogTitle>
                                {t('billing.no_project.title')} · {label}
                            </DialogTitle>
                            <DialogDescription>
                                {t('billing.no_project.description')}
                            </DialogDescription>
                        </DialogHeader>
                        <Field
                            id={`${id}-note`}
                            label={t('billing.no_project.note')}
                            optional={t('billing.create_client.optional')}
                            error={error}
                        >
                            <Textarea
                                id={`${id}-note`}
                                rows={3}
                                maxLength={NO_PROJECT_NOTE_MAX}
                                value={note}
                                placeholder={t(
                                    'billing.no_project.note_placeholder',
                                )}
                                onChange={(event) =>
                                    setNote(event.target.value)
                                }
                                aria-invalid={error ? true : undefined}
                                aria-describedby={describedBy(`${id}-note`, {
                                    error,
                                })}
                                data-test="no-project-note"
                            />
                        </Field>
                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button
                                    type="button"
                                    variant="secondary"
                                    disabled={processing}
                                >
                                    {t('billing.create_client.cancel')}
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                disabled={processing}
                                data-test="no-project-confirm"
                            >
                                {processing ? <Spinner /> : null}
                                {t('billing.no_project.confirm')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

/**
 * En la ficha de una factura marcada: en lugar de «Proyecto y bolsa», quién la marcó, cuándo y por
 * qué, con «Necesita proyecto» para deshacerlo.
 */
export function NoProjectNeededPanel({
    invoice,
}: {
    invoice: {
        id: number;
        number: string | null;
        no_project: { at: string; by: string | null; note: string | null };
    };
}) {
    const [processing, setProcessing] = useState(false);
    const mark = invoice.no_project;

    return (
        <PageSection title={t('billing.links.title')}>
            <div
                className="grid gap-2 rounded-md border bg-card p-3 text-sm"
                data-test="invoice-no-project"
            >
                <p className="flex items-center gap-1.5">
                    <CircleSlash
                        aria-hidden="true"
                        className="size-4 text-muted-foreground"
                    />
                    {t('billing.no_project.marked')}
                </p>
                {mark.note ? (
                    <p className="whitespace-pre-line">«{mark.note}»</p>
                ) : null}
                <p className="text-xs text-muted-foreground">
                    {mark.by
                        ? t('billing.no_project.marked_by', {
                              name: mark.by,
                              date: formatDate(mark.at),
                          })
                        : t('billing.no_project.marked_at', {
                              date: formatDate(mark.at),
                          })}
                </p>
                <div>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        disabled={processing}
                        onClick={() =>
                            router.delete(noProjectUrl(invoice.id), {
                                preserveScroll: true,
                                onStart: () => setProcessing(true),
                                onFinish: () => setProcessing(false),
                            })
                        }
                        aria-label={t('billing.no_project.undo_for', {
                            invoice: invoiceLabel(invoice),
                        })}
                        data-test="no-project-undo"
                    >
                        <Undo2 aria-hidden="true" />
                        {t('billing.no_project.undo')}
                    </Button>
                </div>
            </div>
        </PageSection>
    );
}
