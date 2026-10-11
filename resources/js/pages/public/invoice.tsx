import { Head } from '@inertiajs/react';
import {
    Download,
    FileSearch,
    LinkIcon,
    Paperclip,
    TimerOff,
    TriangleAlert,
} from 'lucide-react';
import { ClientInvoiceStatus } from '@/components/invoicing/client-invoice';
import { CompanyLogo } from '@/components/portal/projects/company-logo';
import type { PortalCompany } from '@/components/portal/projects/types';
import { SkipLink } from '@/components/skip-link';
import { ThemeSync } from '@/components/theme-sync';
import { Button } from '@/components/ui/button';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { ClientInvoice } from '@/types';

/** Los textos llegan del servidor en el idioma del cliente (lang/es/invoicing.php, `public`). */
type Texts = {
    invoice: string;
    credit_note: string;
    from: string;
    to: string;
    issue_date: string;
    due_date: string;
    total: string;
    pending: string;
    paid: string;
    payment: string;
    view_pdf: string;
    download_pdf: string;
    attachments: string;
    expires: string;
    test: string;
    status: Record<
        'paid' | 'partial' | 'overdue' | 'unpaid' | 'cancelled',
        string
    >;
    invalid_title: string;
    invalid_body: string;
    expired_title: string;
    expired_body: string;
};

type Props = {
    problem: 'invalid' | 'expired' | null;
    company: PortalCompany;
    texts: Texts;
    invoice:
        | (ClientInvoice & {
              pdf_url: string;
              download_url: string;
              attachments: {
                  id: number;
                  name: string;
                  size: number;
                  url: string;
              }[];
              expires_at: string;
          })
        | null;
};

/** Importe en el idioma del cliente: «1.234,50 €» o «€1,234.50». */
function money(amount: string, language: string): string {
    return new Intl.NumberFormat(language === 'en' ? 'en-GB' : 'es-ES', {
        style: 'currency',
        currency: 'EUR',
    }).format(Number(amount));
}

function fill(text: string, values: Record<string, string>): string {
    return text.replace(/:([a-z]+)/g, (match, key: string) =>
        key in values ? values[key] : match,
    );
}

/**
 * La factura desde el enlace firmado del email (PLAN-EMISION §3.4, H-098; E2, D-440), sin usuario:
 * con la cabecera de marca del portal, lo que importa al cliente (número, total, lo pendiente, el
 * estado y las fechas) y el PDF para verlo o descargarlo. Caducado o manipulado: lo explica y dice
 * cómo pedir otro. Nada interno.
 */
export default function PublicInvoice({
    problem,
    company,
    texts,
    invoice,
}: Props) {
    const language = invoice?.language ?? 'es';
    const title = invoice
        ? `${invoice.type === 'credit_note' ? texts.credit_note : texts.invoice} ${invoice.number ?? ''}`
        : problem === 'expired'
          ? texts.expired_title
          : texts.invalid_title;

    return (
        <>
            <Head title={title} />
            <ThemeSync />
            <SkipLink />
            <div className="flex min-h-dvh flex-col bg-background">
                <header className="dark text-foreground bg-brand-gradient">
                    <div className="mx-auto flex h-16 w-full max-w-3xl items-center gap-3 px-4 sm:px-6">
                        <CompanyLogo company={company} />
                        <span className="text-sm text-on-gradient-muted">
                            {company.name}
                        </span>
                    </div>
                </header>

                <main
                    id="contenido"
                    tabIndex={-1}
                    className="mx-auto w-full max-w-3xl flex-1 px-4 py-8 focus:outline-none sm:px-6 sm:py-12"
                >
                    {invoice === null ? (
                        <section
                            className="grid justify-items-start gap-3 rounded-md border bg-card p-6"
                            data-test="public-invoice-problem"
                        >
                            {problem === 'expired' ? (
                                <TimerOff
                                    aria-hidden="true"
                                    className="size-8 text-muted-foreground"
                                    strokeWidth={1.5}
                                />
                            ) : (
                                <LinkIcon
                                    aria-hidden="true"
                                    className="size-8 text-muted-foreground"
                                    strokeWidth={1.5}
                                />
                            )}
                            <h1 className="text-2xl font-normal">{title}</h1>
                            <p className="max-w-prose text-sm text-muted-foreground">
                                {problem === 'expired'
                                    ? texts.expired_body
                                    : texts.invalid_body}
                            </p>
                        </section>
                    ) : (
                        <article
                            className="grid gap-6"
                            aria-labelledby="public-invoice-title"
                            data-test="public-invoice"
                        >
                            {invoice.is_test ? (
                                <p className="flex items-center gap-2 rounded-md bg-warning-soft px-3 py-2 text-sm">
                                    <TriangleAlert
                                        aria-hidden="true"
                                        className="size-4 shrink-0 text-warning"
                                    />
                                    {texts.test}
                                </p>
                            ) : null}

                            <header className="grid gap-2">
                                <p className="text-sm text-muted-foreground">
                                    {texts.from}{' '}
                                    {invoice.issuer ?? company.name}
                                    {invoice.client ? (
                                        <>
                                            {' · '}
                                            {texts.to} {invoice.client}
                                        </>
                                    ) : null}
                                </p>
                                <h1
                                    id="public-invoice-title"
                                    className="text-3xl font-normal tracking-tight"
                                >
                                    {title}
                                </h1>
                                <ClientInvoiceStatus
                                    collection={invoice.collection}
                                    label={
                                        texts.status[
                                            invoice.collection as keyof Texts['status']
                                        ] ?? ''
                                    }
                                />
                            </header>

                            <section className="grid gap-4 rounded-md border bg-card p-5 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                                <div className="grid gap-1">
                                    <p className="text-sm text-muted-foreground">
                                        {Number(invoice.pending) > 0
                                            ? texts.pending
                                            : texts.total}
                                    </p>
                                    <p className="tabular text-4xl">
                                        {money(
                                            Number(invoice.pending) > 0
                                                ? invoice.pending
                                                : invoice.total,
                                            language,
                                        )}
                                    </p>
                                    {Number(invoice.pending) > 0 &&
                                    invoice.pending !== invoice.total ? (
                                        <p className="tabular text-sm text-muted-foreground">
                                            {texts.total}:{' '}
                                            {money(invoice.total, language)} ·{' '}
                                            {texts.paid}:{' '}
                                            {money(
                                                invoice.paid_total,
                                                language,
                                            )}
                                        </p>
                                    ) : null}
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    <Button asChild>
                                        <a
                                            href={invoice.pdf_url}
                                            target="_blank"
                                            rel="noopener"
                                            data-test="public-invoice-pdf"
                                        >
                                            <FileSearch aria-hidden="true" />
                                            {texts.view_pdf}
                                        </a>
                                    </Button>
                                    <Button variant="outline" asChild>
                                        <a href={invoice.download_url}>
                                            <Download aria-hidden="true" />
                                            {texts.download_pdf}
                                        </a>
                                    </Button>
                                </div>
                            </section>

                            <dl className="grid gap-3 text-sm sm:grid-cols-2">
                                <div className="grid gap-0.5">
                                    <dt className="text-muted-foreground">
                                        {texts.issue_date}
                                    </dt>
                                    <dd className="tabular">
                                        {formatDate(invoice.issue_date)}
                                    </dd>
                                </div>
                                {invoice.due_date ? (
                                    <div className="grid gap-0.5">
                                        <dt className="text-muted-foreground">
                                            {texts.due_date}
                                        </dt>
                                        <dd className="tabular">
                                            {formatDate(invoice.due_date)}
                                        </dd>
                                    </div>
                                ) : null}
                                {invoice.payment_text ? (
                                    <div className="grid gap-0.5 sm:col-span-2">
                                        <dt className="text-muted-foreground">
                                            {texts.payment}
                                        </dt>
                                        <dd className="whitespace-pre-line">
                                            {invoice.payment_text}
                                        </dd>
                                    </div>
                                ) : null}
                            </dl>

                            {invoice.attachments.length > 0 ? (
                                <section className="grid gap-2">
                                    <h2 className="text-base font-normal">
                                        {texts.attachments}
                                    </h2>
                                    <ul className="grid gap-1 text-sm">
                                        {invoice.attachments.map(
                                            (attachment) => (
                                                <li key={attachment.id}>
                                                    <a
                                                        href={attachment.url}
                                                        className={cn(
                                                            'inline-flex items-center gap-1.5 rounded-md hover:underline',
                                                            FOCUS_RING,
                                                        )}
                                                    >
                                                        <Paperclip
                                                            aria-hidden="true"
                                                            className="size-3.5 text-muted-foreground"
                                                        />
                                                        {attachment.name}
                                                    </a>
                                                </li>
                                            ),
                                        )}
                                    </ul>
                                </section>
                            ) : null}

                            <p className="border-t pt-4 text-xs text-muted-foreground">
                                {fill(texts.expires, {
                                    date: formatDate(
                                        invoice.expires_at.slice(0, 10),
                                    ),
                                })}
                            </p>
                        </article>
                    )}
                </main>
            </div>
        </>
    );
}
