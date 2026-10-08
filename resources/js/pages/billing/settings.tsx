import { Head, router, useForm } from '@inertiajs/react';
import {
    CircleAlert,
    CircleCheck,
    Clock,
    RefreshCw,
    ShieldCheck,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useId } from 'react';
import { BillingTabs } from '@/components/billing/billing-nav';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/projects-list/page-header';
import { PageSection } from '@/components/projects-list/page-section';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime, formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type Issuer = {
    legal_name: string | null;
    tax_id: string | null;
    address: string | null;
    postal_code: string | null;
    city: string | null;
    province: string | null;
    country_code: string | null;
    registry: string | null;
    iban: string | null;
    email: string | null;
    phone: string | null;
};

type Run = {
    id: number;
    trigger: 'schedule' | 'manual' | 'command' | 'seeder';
    user: string | null;
    status: 'running' | 'ok' | 'failed';
    stats: Record<string, number>;
    error: string | null;
    started_at: string;
    finished_at: string | null;
};

type Props = {
    issuer: Issuer;
    holded: {
        driver: 'fake' | 'holded';
        configured: boolean;
        base_url: string;
        per_minute: number;
        running: boolean;
        scheduled: boolean;
    };
    runs: Run[];
    can: { sync: boolean };
};

const FIELDS: { key: keyof Issuer; wide?: boolean; autoComplete?: string }[] = [
    { key: 'legal_name', wide: true, autoComplete: 'organization' },
    { key: 'tax_id' },
    { key: 'registry' },
    { key: 'address', wide: true, autoComplete: 'street-address' },
    { key: 'postal_code', autoComplete: 'postal-code' },
    { key: 'city', autoComplete: 'address-level2' },
    { key: 'province', autoComplete: 'address-level1' },
    { key: 'country_code', autoComplete: 'country' },
    { key: 'iban' },
    { key: 'email', autoComplete: 'email' },
    { key: 'phone', autoComplete: 'tel' },
];

/**
 * Ajustes de Facturación (Fase 12, F1; D-383 y D-387): los datos fiscales del emisor (Audax), la
 * conexión con Holded (sin enseñar nunca la clave) y las últimas sincronizaciones, con «Sincronizar
 * ahora» para los admins.
 */
export default function BillingSettings({ issuer, holded, runs, can }: Props) {
    const id = useId();
    const form = useForm<Record<keyof Issuer, string>>(
        Object.fromEntries(
            FIELDS.map(({ key }) => [
                key,
                issuer[key] ?? (key === 'country_code' ? 'ES' : ''),
            ]),
        ) as Record<keyof Issuer, string>,
    );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put('/facturacion/ajustes', { preserveScroll: true });
    };

    return (
        <>
            <Head title={t('billing.settings.title')} />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('billing.section')}
                    description={t('billing.settings.description')}
                />
                <BillingTabs current="ajustes" />

                <div className="grid gap-8 xl:grid-cols-[minmax(0,1fr)_minmax(0,26rem)]">
                    <PageSection
                        title={t('billing.issuer.title')}
                        description={t('billing.issuer.description')}
                    >
                        <form
                            onSubmit={submit}
                            noValidate
                            className="grid gap-4 sm:grid-cols-2"
                        >
                            {FIELDS.map(({ key, wide, autoComplete }) => (
                                <div
                                    key={key}
                                    className={cn(
                                        'grid content-start gap-1',
                                        wide && 'sm:col-span-2',
                                    )}
                                >
                                    <Label htmlFor={`${id}-${key}`}>
                                        {t(`billing.issuer.${key}`)}
                                    </Label>
                                    <Input
                                        id={`${id}-${key}`}
                                        value={form.data[key]}
                                        autoComplete={autoComplete}
                                        maxLength={
                                            key === 'country_code'
                                                ? 2
                                                : undefined
                                        }
                                        aria-invalid={
                                            form.errors[key] ? true : undefined
                                        }
                                        onChange={(event) =>
                                            form.setData(
                                                key,
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <InputError message={form.errors[key]} />
                                </div>
                            ))}
                            <div className="sm:col-span-2">
                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                >
                                    {t('billing.issuer.save')}
                                </Button>
                            </div>
                        </form>
                    </PageSection>

                    <PageSection
                        title={t('billing.holded.title')}
                        description={t('billing.holded.description')}
                    >
                        <Card className="py-4">
                            <CardContent className="grid gap-3 px-4 text-sm">
                                <p className="flex items-center gap-2">
                                    {holded.configured ? (
                                        <CircleCheck
                                            aria-hidden="true"
                                            className="size-4 text-success"
                                        />
                                    ) : (
                                        <CircleAlert
                                            aria-hidden="true"
                                            className="size-4 text-warning"
                                        />
                                    )}
                                    {t(
                                        holded.driver === 'fake'
                                            ? 'billing.holded.fake'
                                            : holded.configured
                                              ? 'billing.holded.configured'
                                              : 'billing.holded.missing_key',
                                    )}
                                </p>
                                <p className="flex items-center gap-2 text-muted-foreground">
                                    <ShieldCheck
                                        aria-hidden="true"
                                        className="size-4"
                                    />
                                    {t('billing.holded.read_only')}
                                </p>
                                <p className="flex items-center gap-2 text-muted-foreground">
                                    <Clock
                                        aria-hidden="true"
                                        className="size-4"
                                    />
                                    {t(
                                        holded.scheduled
                                            ? 'billing.holded.nightly'
                                            : 'billing.holded.nightly_off',
                                        {
                                            per_minute: holded.per_minute,
                                        },
                                    )}
                                </p>
                                {can.sync ? (
                                    <div>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            disabled={
                                                !holded.configured ||
                                                holded.running
                                            }
                                            onClick={() =>
                                                router.post(
                                                    '/facturacion/sincronizar',
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                            data-test="holded-sync"
                                        >
                                            <RefreshCw
                                                aria-hidden="true"
                                                className={cn(
                                                    holded.running &&
                                                        'animate-spin',
                                                )}
                                            />
                                            {t(
                                                holded.running
                                                    ? 'billing.holded.running'
                                                    : 'billing.holded.sync_now',
                                            )}
                                        </Button>
                                    </div>
                                ) : null}
                            </CardContent>
                        </Card>

                        <h3 className="text-base font-normal">
                            {t('billing.runs.title')}
                        </h3>
                        {runs.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('billing.runs.none')}
                            </p>
                        ) : (
                            <ol className="grid gap-2" data-test="holded-runs">
                                {runs.map((run) => (
                                    <li
                                        key={run.id}
                                        className="grid gap-1 rounded-md border px-3 py-2 text-sm"
                                    >
                                        <p className="flex flex-wrap items-center gap-2">
                                            {run.status === 'ok' ? (
                                                <CircleCheck
                                                    aria-hidden="true"
                                                    className="size-4 text-success"
                                                />
                                            ) : run.status === 'failed' ? (
                                                <CircleAlert
                                                    aria-hidden="true"
                                                    className="size-4 text-danger"
                                                />
                                            ) : (
                                                <RefreshCw
                                                    aria-hidden="true"
                                                    className="size-4 animate-spin text-info"
                                                />
                                            )}
                                            <span>
                                                {t(
                                                    `billing.runs.status.${run.status}`,
                                                )}
                                            </span>
                                            <span className="text-muted-foreground">
                                                ·{' '}
                                                {formatDateTime(run.started_at)}{' '}
                                                ·{' '}
                                                {t(
                                                    `billing.runs.trigger.${run.trigger}`,
                                                )}
                                                {run.user
                                                    ? ` (${run.user})`
                                                    : ''}
                                            </span>
                                        </p>
                                        {run.status === 'ok' ? (
                                            <p className="text-muted-foreground">
                                                {t('billing.runs.stats', {
                                                    invoices: formatNumber(
                                                        (run.stats
                                                            .invoices_created ??
                                                            0) +
                                                            (run.stats
                                                                .invoices_updated ??
                                                                0) +
                                                            (run.stats
                                                                .invoices_unchanged ??
                                                                0),
                                                    ),
                                                    created: formatNumber(
                                                        run.stats
                                                            .invoices_created ??
                                                            0,
                                                    ),
                                                    linked: formatNumber(
                                                        run.stats
                                                            .linked_invoices ??
                                                            0,
                                                    ),
                                                    unresolved: formatNumber(
                                                        run.stats
                                                            .contacts_unresolved ??
                                                            0,
                                                    ),
                                                })}
                                            </p>
                                        ) : null}
                                        {run.error ? (
                                            <p className="text-danger">
                                                {run.error}
                                            </p>
                                        ) : null}
                                    </li>
                                ))}
                            </ol>
                        )}
                    </PageSection>
                </div>
            </div>
        </>
    );
}

BillingSettings.layout = {
    breadcrumbs: [
        { title: t('billing.section'), href: '/facturacion/facturas' },
        { title: t('billing.settings.title'), href: '/facturacion/ajustes' },
    ],
};
