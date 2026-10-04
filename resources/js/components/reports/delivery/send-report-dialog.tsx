import { useForm } from '@inertiajs/react';
import { Send } from 'lucide-react';
import { useId, useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
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
import { t } from '@/lib/i18n';
import { send } from '@/routes/reports/deliveries';
import type { ReportRequestData } from '@/types/reports';
import type {
    DeliveryData,
    DeliveryErrors,
    DeliveryPayload,
} from './delivery-fields';
import {
    FormatsField,
    MAX_RECIPIENTS,
    MessageFields,
    RecipientsField,
    withDraft,
} from './delivery-fields';
import { useDeliveryPeople } from './use-delivery-people';

function initialData(title: string): DeliveryData {
    return {
        title,
        formats: ['pdf'],
        recipient_user_ids: [],
        recipient_emails: [],
        subject: '',
        message: '',
    };
}

/**
 * «Enviar por correo…» del menú «Exportar ▾» de cada informe (D-141): el informe con los filtros
 * de la pantalla, en PDF, Excel o los dos, a personas de la app y a correos externos. Se genera en
 * la cola con los permisos de quien lo envía; al terminar, el aviso llega con el toast del servidor.
 * El formulario vive dentro del contenido del diálogo: cada vez que se abre empieza de cero.
 */
export function SendReportDialog({
    open,
    onOpenChange,
    request,
    title,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    request: ReportRequestData;
    title: string;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto sm:max-w-xl"
                data-test="send-report-dialog"
            >
                <SendReportForm
                    request={request}
                    title={title}
                    onDone={() => onOpenChange(false)}
                />
            </DialogContent>
        </Dialog>
    );
}

function SendReportForm({
    request,
    title,
    onDone,
}: {
    request: ReportRequestData;
    title: string;
    onDone: () => void;
}) {
    const id = useId();
    const form = useForm<DeliveryData>(initialData(title));
    const [draft, setDraft] = useState('');
    const [draftError, setDraftError] = useState<string | null>(null);
    const { people, failed } = useDeliveryPeople(true);
    const errors = form.errors as DeliveryErrors;

    const set = <K extends keyof DeliveryData>(
        key: K,
        value: DeliveryData[K],
    ) => form.setData((data) => ({ ...data, [key]: value }));

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const emails = withDraft(form.data.recipient_emails, draft);

        if (emails === null) {
            setDraftError(t('deliveries.recipients.invalid', { email: draft }));

            return;
        }

        setDraftError(null);
        form.transform((data): DeliveryPayload => ({
            ...data,
            request,
            recipient_emails: emails,
        }));
        form.post(send.url(), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: onDone,
        });
    };

    const total =
        form.data.recipient_user_ids.length + form.data.recipient_emails.length;
    const generalError = errors.request ?? errors.title;

    return (
        <form noValidate onSubmit={submit} className="grid gap-6">
            <DialogHeader>
                <DialogTitle>{t('deliveries.send.title')}</DialogTitle>
                <DialogDescription>
                    {t('deliveries.send.description', { title })}
                </DialogDescription>
            </DialogHeader>

            <InputError message={generalError} />

            <FormatsField
                id={`${id}-formats`}
                value={form.data.formats}
                onChange={(formats) => set('formats', formats)}
                error={errors.formats ?? errors['formats.0']}
            />

            <RecipientsField
                id={`${id}-recipients`}
                people={people}
                peopleFailed={failed}
                userIds={form.data.recipient_user_ids}
                emails={form.data.recipient_emails}
                draft={draft}
                onUserIds={(ids) => set('recipient_user_ids', ids)}
                onEmails={(emails) => set('recipient_emails', emails)}
                onDraft={(value) => {
                    setDraftError(null);
                    setDraft(value);
                }}
                errors={
                    draftError
                        ? { ...errors, recipient_emails: draftError }
                        : errors
                }
            />

            <MessageFields
                id={`${id}-message`}
                title={title}
                subject={form.data.subject}
                message={form.data.message}
                onSubject={(subject) => set('subject', subject)}
                onMessage={(message) => set('message', message)}
                errors={errors}
            />

            <DialogFooter className="gap-2">
                <DialogClose asChild>
                    <Button
                        type="button"
                        variant="secondary"
                        disabled={form.processing}
                    >
                        {t('common.cancel')}
                    </Button>
                </DialogClose>
                <Button
                    type="submit"
                    disabled={
                        form.processing ||
                        form.data.formats.length === 0 ||
                        total > MAX_RECIPIENTS
                    }
                >
                    {form.processing ? (
                        <Spinner />
                    ) : (
                        <Send aria-hidden="true" />
                    )}
                    {t('deliveries.send.submit')}
                </Button>
            </DialogFooter>
        </form>
    );
}
