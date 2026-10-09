import { router, useForm } from '@inertiajs/react';
import { Link2, TriangleAlert, UserRoundPlus } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import { describedBy, Field } from '@/components/admin/field';
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
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import type {
    ClientCandidate,
    ClientDraft,
    ClientDraftResponse,
} from '@/types/billing-rules';

type DraftForm = { [K in keyof ClientDraft]: string };

const EMPTY: DraftForm = {
    name: '',
    tax_id: '',
    email: '',
    legal_name: '',
    address: '',
    postal_code: '',
    city: '',
    province: '',
    country_code: 'ES',
};

const contactUrl = (id: number) => `/facturacion/contactos/${id}`;

/** Pide los datos del diálogo y los clientes parecidos (D-430). Exportada para los tests. */
export async function fetchClientDraft(
    contactId: number,
    signal?: AbortSignal,
): Promise<ClientDraftResponse> {
    const response = await fetch(`${contactUrl(contactId)}/cliente`, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        signal,
    });

    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }

    return (await response.json()) as ClientDraftResponse;
}

/** Los datos del contacto como valores del formulario (los vacíos, como texto vacío). */
export function draftToForm(draft: ClientDraft): DraftForm {
    return Object.fromEntries(
        Object.entries({ ...EMPTY, ...draft }).map(([key, value]) => [
            key,
            value ?? '',
        ]),
    ) as DraftForm;
}

/**
 * «Crear cliente» desde un contacto de Holded sin cliente (D-430, cambia D-387), en «Por revisar» y
 * en el directorio de Ajustes: el botón abre un diálogo con los datos ya rellenos y editables
 * (nombre, NIF, email y la ficha fiscal). Si ya hay un cliente con el mismo NIF o un nombre muy
 * parecido, lo dice y deja casar el contacto con él en vez de crear otro.
 */
export function CreateClientButton({
    contact,
    disabled,
    compact = false,
}: {
    contact: { id: number; name: string };
    disabled?: boolean;
    /** En las filas estrechas, solo el icono por debajo de lg (como «Descartar»). */
    compact?: boolean;
}) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <Button
                type="button"
                size="sm"
                variant="ghost"
                disabled={disabled}
                onClick={() => setOpen(true)}
                aria-label={t('billing.create_client.action_for', {
                    contact: contact.name,
                })}
                title={compact ? t('billing.create_client.action') : undefined}
                className="whitespace-nowrap"
                data-test="create-client"
            >
                <UserRoundPlus aria-hidden="true" />
                <span className={compact ? 'sm:sr-only 2xl:not-sr-only' : ''}>
                    {t('billing.create_client.action')}
                </span>
            </Button>
            {open ? (
                <CreateClientDialog
                    contact={contact}
                    open={open}
                    onOpenChange={setOpen}
                />
            ) : null}
        </>
    );
}

export function CreateClientDialog({
    contact,
    open,
    onOpenChange,
}: {
    contact: { id: number; name: string };
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const id = useId();
    const [status, setStatus] = useState<'loading' | 'ready' | 'error'>(
        'loading',
    );
    const [candidates, setCandidates] = useState<ClientCandidate[]>([]);
    const [matching, setMatching] = useState(false);
    const form = useForm<DraftForm & { confirmed: boolean }>({
        ...EMPTY,
        confirmed: false,
    });
    const errors = form.errors as Record<string, string | undefined>;

    useEffect(() => {
        if (!open) {
            return;
        }
        const controller = new AbortController();
        fetchClientDraft(contact.id, controller.signal)
            .then((data) => {
                form.setData({ ...draftToForm(data.draft), confirmed: false });
                setCandidates(data.candidates);
                setStatus('ready');
            })
            .catch(() => {
                if (!controller.signal.aborted) {
                    setStatus('error');
                }
            });

        return () => controller.abort();
        // Solo al abrir (el formulario es del diálogo y no debe rehacerse al escribir).
    }, [open, contact.id]);

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            confirmed: candidates.length > 0,
        }));
        form.post(`${contactUrl(contact.id)}/cliente`, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    const match = (candidate: ClientCandidate) =>
        router.put(
            contactUrl(contact.id),
            { action: 'assign', client_id: candidate.id },
            {
                preserveScroll: true,
                onStart: () => setMatching(true),
                onFinish: () => setMatching(false),
                onSuccess: () => onOpenChange(false),
            },
        );

    const text = (
        key: keyof DraftForm,
        label: string,
        props: {
            type?: string;
            maxLength?: number;
            required?: boolean;
            help?: string;
            autoComplete?: string;
            className?: string;
        } = {},
    ) => {
        const inputId = `${id}-${key}`;

        return (
            <Field
                id={inputId}
                label={label}
                optional={
                    props.required
                        ? undefined
                        : t('billing.create_client.optional')
                }
                help={props.help}
                error={errors[key]}
                className={props.className}
            >
                <Input
                    id={inputId}
                    type={props.type ?? 'text'}
                    value={form.data[key]}
                    onChange={(event) => form.setData(key, event.target.value)}
                    required={props.required}
                    maxLength={props.maxLength}
                    autoComplete={props.autoComplete ?? 'off'}
                    aria-invalid={errors[key] ? true : undefined}
                    aria-describedby={describedBy(inputId, {
                        help: Boolean(props.help),
                        error: errors[key],
                    })}
                    data-test={`create-client-${key}`}
                />
            </Field>
        );
    };

    const busy = form.processing || matching;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto sm:max-w-2xl"
                data-test="create-client-dialog"
            >
                <form onSubmit={submit} className="grid gap-6" noValidate>
                    <DialogHeader>
                        <DialogTitle>
                            {t('billing.create_client.title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('billing.create_client.description', {
                                contact: contact.name,
                            })}
                        </DialogDescription>
                    </DialogHeader>

                    {status === 'loading' ? (
                        <p
                            role="status"
                            className="flex items-center gap-2 text-sm text-muted-foreground"
                        >
                            <Spinner />
                            {t('billing.create_client.loading')}
                        </p>
                    ) : status === 'error' ? (
                        <InputError
                            message={t('billing.create_client.load_error')}
                        />
                    ) : (
                        <>
                            {candidates.length > 0 ? (
                                <section
                                    aria-labelledby={`${id}-duplicates`}
                                    className="grid gap-2 rounded-md bg-warning-soft px-3 py-3 text-sm text-foreground"
                                    data-test="create-client-candidates"
                                >
                                    <h3
                                        id={`${id}-duplicates`}
                                        className="flex items-center gap-2 font-normal"
                                    >
                                        <TriangleAlert
                                            aria-hidden="true"
                                            className="size-4 shrink-0 text-warning"
                                        />
                                        {t(
                                            'billing.create_client.duplicates_title',
                                        )}
                                    </h3>
                                    <p className="text-muted-foreground">
                                        {t(
                                            'billing.create_client.duplicates_description',
                                        )}
                                    </p>
                                    <ul className="grid gap-1.5">
                                        {candidates.map((candidate) => (
                                            <li
                                                key={candidate.id}
                                                className="flex flex-wrap items-center justify-between gap-2 rounded-md border bg-card px-3 py-2"
                                            >
                                                <span className="min-w-0">
                                                    <span className="block truncate">
                                                        {candidate.name}
                                                        {candidate.is_active
                                                            ? ''
                                                            : ` (${t('billing.create_client.inactive')})`}
                                                    </span>
                                                    <span className="block text-xs text-muted-foreground">
                                                        {[
                                                            t(
                                                                `billing.create_client.reason.${candidate.reason}`,
                                                            ),
                                                            candidate.tax_id,
                                                        ]
                                                            .filter(Boolean)
                                                            .join(' · ')}
                                                    </span>
                                                </span>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    disabled={busy}
                                                    onClick={() =>
                                                        match(candidate)
                                                    }
                                                    aria-label={t(
                                                        'billing.create_client.match_with',
                                                        {
                                                            contact:
                                                                contact.name,
                                                            client: candidate.name,
                                                        },
                                                    )}
                                                    data-test="create-client-match"
                                                >
                                                    <Link2 aria-hidden="true" />
                                                    {t(
                                                        'billing.create_client.match',
                                                    )}
                                                </Button>
                                            </li>
                                        ))}
                                    </ul>
                                </section>
                            ) : null}

                            <div className="grid gap-5 sm:grid-cols-2">
                                {text('name', t('billing.create_client.name'), {
                                    required: true,
                                    maxLength: 255,
                                    help: t('billing.create_client.name_help'),
                                    className: 'sm:col-span-2',
                                })}
                                {text(
                                    'tax_id',
                                    t('billing.create_client.tax_id'),
                                    { maxLength: 32 },
                                )}
                                {text(
                                    'email',
                                    t('billing.create_client.email'),
                                    {
                                        type: 'email',
                                        maxLength: 255,
                                    },
                                )}
                            </div>

                            <fieldset className="grid gap-5 sm:grid-cols-2">
                                <legend className="mb-3 text-sm text-muted-foreground">
                                    {t('billing.create_client.fiscal')}
                                </legend>
                                {text(
                                    'legal_name',
                                    t('billing.create_client.legal_name'),
                                    {
                                        maxLength: 200,
                                        className: 'sm:col-span-2',
                                    },
                                )}
                                {text(
                                    'address',
                                    t('billing.create_client.address'),
                                    {
                                        maxLength: 255,
                                        className: 'sm:col-span-2',
                                    },
                                )}
                                {text(
                                    'postal_code',
                                    t('billing.create_client.postal_code'),
                                    { maxLength: 16 },
                                )}
                                {text('city', t('billing.create_client.city'), {
                                    maxLength: 120,
                                })}
                                {text(
                                    'province',
                                    t('billing.create_client.province'),
                                    { maxLength: 120 },
                                )}
                                {text(
                                    'country_code',
                                    t('billing.create_client.country'),
                                    {
                                        required: true,
                                        maxLength: 2,
                                        help: t(
                                            'billing.create_client.country_help',
                                        ),
                                    },
                                )}
                            </fieldset>

                            <InputError message={errors.candidates} />
                        </>
                    )}

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button
                                type="button"
                                variant="secondary"
                                disabled={busy}
                            >
                                {t('billing.create_client.cancel')}
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            disabled={busy || status !== 'ready'}
                            data-test="create-client-submit"
                        >
                            {form.processing ? <Spinner /> : null}
                            {t(
                                candidates.length > 0
                                    ? 'billing.create_client.submit_anyway'
                                    : 'billing.create_client.submit',
                            )}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
