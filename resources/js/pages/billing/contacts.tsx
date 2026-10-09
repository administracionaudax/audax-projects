import { Head, Link, router } from '@inertiajs/react';
import { Ban, Check, RotateCcw, UserRoundCheck, Users } from 'lucide-react';
import { useId, useState } from 'react';
import { BillingHeader } from '@/components/billing/billing-header';
import { ViewTabs } from '@/components/billing/view-tabs';
import { EmptyState } from '@/components/empty-state';
import { SearchableSelect } from '@/components/domain/searchable-select';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatCurrency } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type View = 'sin-casar' | 'por-revisar' | 'todos' | 'descartados';

type Contact = {
    id: number;
    name: string;
    trade_name: string | null;
    tax_id: string | null;
    email: string | null;
    city: string | null;
    client: { id: number; name: string } | null;
    match_method: 'tax_id' | 'name' | 'approx' | 'manual' | null;
    ignored: boolean;
    invoices: number;
    invoiced: string;
};

type Props = {
    view: View;
    counts: Record<View, number>;
    contacts: Contact[];
    clients: {
        id: number;
        name: string;
        is_active: boolean;
        tax_id: string | null;
    }[];
};

const URL = '/facturacion/por-revisar';
const VIEWS: View[] = ['sin-casar', 'por-revisar', 'todos', 'descartados'];

/**
 * Por revisar (D-405): hoy, los contactos de Holded y su cliente de Audax (Fase 12, F1; D-387). Los
 * que no casan por NIF ni por nombre se resuelven aquí, eligiendo su cliente o descartándolos; nunca
 * se crea un cliente. La bandeja única con las facturas sin proyecto llega con I5.
 */
export default function HoldedContacts({
    view,
    counts,
    contacts,
    clients,
}: Props) {
    return (
        <>
            <Head title={t('billing.nav.review')} />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <BillingHeader
                    current="por-revisar"
                    title={t('billing.nav.review')}
                    description={t('billing.contacts.description')}
                />

                <ViewTabs
                    label={t('billing.contacts.views')}
                    current={view}
                    tabs={VIEWS.map((item) => ({
                        id: item,
                        label: t(`billing.contacts.view.${item}`),
                        href:
                            item === 'sin-casar' ? URL : `${URL}?vista=${item}`,
                        count: counts[item],
                    }))}
                />

                {contacts.length === 0 ? (
                    <EmptyState
                        icon={view === 'sin-casar' ? UserRoundCheck : Users}
                        title={t(
                            view === 'sin-casar'
                                ? 'billing.contacts.all_resolved'
                                : 'billing.contacts.none',
                        )}
                        description={t(
                            view === 'sin-casar'
                                ? 'billing.contacts.all_resolved_description'
                                : 'billing.contacts.none_description',
                        )}
                    />
                ) : (
                    <ul className="grid gap-3" data-test="holded-contacts">
                        {contacts.map((contact) => (
                            <ContactRow
                                key={contact.id}
                                contact={contact}
                                clients={clients}
                            />
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

function ContactRow({
    contact,
    clients,
}: {
    contact: Contact;
    clients: Props['clients'];
}) {
    const id = useId();
    const [clientId, setClientId] = useState<string>(
        contact.client ? String(contact.client.id) : '',
    );
    const [processing, setProcessing] = useState(false);

    const send = (action: 'assign' | 'ignore' | 'auto' | 'confirm') => {
        router.put(
            `/facturacion/contactos/${contact.id}`,
            {
                action,
                client_id: action === 'assign' ? Number(clientId) : null,
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <li className="grid gap-3 rounded-md border bg-card p-4 md:grid-cols-[minmax(0,1fr)_minmax(0,22rem)] md:items-end">
            <div className="min-w-0 space-y-1">
                <p className="text-base">{contact.name}</p>
                <p className="text-sm text-muted-foreground">
                    {[
                        contact.trade_name &&
                        contact.trade_name !== contact.name
                            ? contact.trade_name
                            : null,
                        contact.tax_id
                            ? t('billing.contacts.tax_id', {
                                  tax_id: contact.tax_id,
                              })
                            : t('billing.contacts.no_tax_id'),
                        contact.city,
                        contact.email,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                </p>
                <p className="text-sm">
                    {t(
                        contact.invoices === 1
                            ? 'billing.contacts.invoices_one'
                            : 'billing.contacts.invoices_other',
                        {
                            count: contact.invoices,
                            amount: formatCurrency(contact.invoiced),
                        },
                    )}
                </p>
                {contact.client ? (
                    <p className="flex items-center gap-1.5 text-sm">
                        <Check
                            aria-hidden="true"
                            className="size-4 text-success"
                        />
                        <Link
                            href={`/clientes/${contact.client.id}/facturacion`}
                            className={cn(
                                'rounded-md hover:underline',
                                FOCUS_RING,
                            )}
                        >
                            {contact.client.name}
                        </Link>
                        <span className="text-muted-foreground">
                            ·{' '}
                            {t(
                                `billing.contacts.match.${contact.match_method ?? 'manual'}`,
                            )}
                        </span>
                    </p>
                ) : contact.ignored ? (
                    <p className="flex items-center gap-1.5 text-sm text-muted-foreground">
                        <Ban aria-hidden="true" className="size-4" />
                        {t('billing.contacts.ignored')}
                    </p>
                ) : null}
            </div>

            <div className="grid gap-2">
                <Label htmlFor={`${id}-client`}>
                    {t('billing.contacts.client')}
                </Label>
                <div className="flex gap-2">
                    <SearchableSelect
                        id={`${id}-client`}
                        value={clientId === '' ? null : clientId}
                        placeholder={t('billing.contacts.choose_client')}
                        search={t('billing.contacts.search_client')}
                        empty={t('billing.contacts.no_client_found')}
                        dataTest="contact-client"
                        groups={[
                            {
                                label: null,
                                options: clients.map((client) => ({
                                    value: String(client.id),
                                    label: client.is_active
                                        ? client.name
                                        : t(
                                              'billing.contacts.inactive_client',
                                              { name: client.name },
                                          ),
                                    hint: client.tax_id,
                                })),
                            },
                        ]}
                        onChange={setClientId}
                    />
                    <Button
                        type="button"
                        disabled={
                            processing ||
                            clientId === '' ||
                            clientId === String(contact.client?.id ?? '')
                        }
                        onClick={() => send('assign')}
                    >
                        {t('billing.contacts.assign')}
                    </Button>
                </div>
                <div className="flex flex-wrap gap-2">
                    {contact.match_method === 'approx' && contact.client ? (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={processing}
                            onClick={() => send('confirm')}
                            data-test="contact-confirm"
                        >
                            <Check aria-hidden="true" />
                            {t('billing.contacts.confirm', {
                                client: contact.client.name,
                            })}
                        </Button>
                    ) : null}
                    {!contact.ignored ? (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            disabled={processing}
                            onClick={() => send('ignore')}
                        >
                            <Ban aria-hidden="true" />
                            {t('billing.contacts.ignore')}
                        </Button>
                    ) : null}
                    {contact.match_method === 'manual' || contact.ignored ? (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            disabled={processing}
                            onClick={() => send('auto')}
                        >
                            <RotateCcw aria-hidden="true" />
                            {t('billing.contacts.auto')}
                        </Button>
                    ) : null}
                </div>
            </div>
        </li>
    );
}

HoldedContacts.layout = {
    breadcrumbs: [
        { title: t('billing.section'), href: '/facturacion' },
        { title: t('billing.nav.review'), href: URL },
    ],
};
