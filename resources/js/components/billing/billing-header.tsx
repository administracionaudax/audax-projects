import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { KeywordText } from '@/components/keyword-text';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { HoldedSyncSummary } from '@/types';
import { BillingSectionSwitcher } from './billing-nav';
import type { BillingSectionId } from './billing-nav';
import { syncTone, timeAgo } from './billing-time';
import type { SyncTone } from './billing-time';

const DOT: Record<SyncTone, string> = {
    ok: 'bg-success',
    stale: 'bg-warning',
    failed: 'bg-danger',
    running: 'bg-primary',
    never: 'bg-muted-foreground',
};

/**
 * Estado de la última lectura de Holded (R6, D-409), discreto, en la cabecera de cada pantalla de
 * Facturación: un punto (verde, ámbar si pasan más de 26 h, rojo si falló) siempre con su texto, y
 * el detalle en el tooltip. Lleva a Ajustes, donde está el historial y «Sincronizar ahora». Solo
 * para quien tiene view-billing (la prop compartida `billingNav`); los demás no lo ven.
 */
export function HoldedSyncStatus({ className }: { className?: string }) {
    const billing = usePage().props.billingNav ?? null;

    if (billing === null) {
        return null;
    }

    const sync: HoldedSyncSummary | null = billing.sync;
    const tone = syncTone(sync);
    const at = sync ? (sync.finished_at ?? sync.started_at) : null;
    const label =
        tone === 'never'
            ? t('billing.sync.status_never')
            : tone === 'running'
              ? t('billing.sync.status_running')
              : tone === 'failed'
                ? t('billing.sync.status_failed', { ago: timeAgo(at ?? '') })
                : t('billing.sync.status_ok', { ago: timeAgo(at ?? '') });
    const detail = [
        at
            ? t(
                  tone === 'failed'
                      ? 'billing.sync.detail_failed'
                      : tone === 'running'
                        ? 'billing.sync.detail_running'
                        : 'billing.sync.detail_ok',
                  { date: formatDateTime(at) },
              )
            : t('billing.sync.never'),
        tone === 'failed' && sync?.last_ok_at
            ? t('billing.sync.detail_last_ok', {
                  date: formatDateTime(sync.last_ok_at),
              })
            : null,
        tone === 'stale' ? t('billing.sync.detail_stale') : null,
    ]
        .filter(Boolean)
        .join(' ');

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Link
                    href="/facturacion/ajustes"
                    className={cn(
                        'inline-flex items-center gap-1.5 rounded-md text-xs text-muted-foreground hover:text-foreground',
                        FOCUS_RING,
                        className,
                    )}
                    data-test="holded-sync-status"
                    data-tone={tone}
                    aria-label={`${label}. ${detail}`}
                >
                    <span
                        aria-hidden="true"
                        className={cn(
                            'size-2 shrink-0 rounded-full',
                            DOT[tone],
                        )}
                    />
                    <span className={cn(tone === 'failed' && 'text-danger')}>
                        {label}
                    </span>
                </Link>
            </TooltipTrigger>
            <TooltipContent className="max-w-72">{detail}</TooltipContent>
        </Tooltip>
    );
}

/**
 * Cabecera de las pantallas de Facturación (D-405): el h1 con el nombre de la pantalla (nunca
 * «Facturación» a secas), su descripción, las acciones y el estado de la lectura de Holded. En el
 * móvil, junto al título, el selector de las demás pantallas de la sección.
 */
export function BillingHeader({
    current,
    title,
    description,
    actions,
    kicker,
}: {
    current: BillingSectionId;
    title: string;
    description?: ReactNode;
    actions?: ReactNode;
    /** Línea pequeña sobre el título (la ficha de una factura: «Factura · cliente»). */
    kicker?: ReactNode;
}) {
    return (
        <header className="flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
            <div className="min-w-0 flex-1 basis-80 space-y-1">
                {kicker ? (
                    <div className="flex items-center gap-1.5 text-sm text-muted-foreground">
                        {kicker}
                    </div>
                ) : null}
                <div className="flex items-center gap-1">
                    <h1 className="min-w-0 text-2xl font-normal tracking-tight">
                        <KeywordText text={title} />
                    </h1>
                    <BillingSectionSwitcher
                        current={current}
                        className="md:hidden"
                    />
                </div>
                {description ? (
                    <div className="text-sm text-muted-foreground">
                        {description}
                    </div>
                ) : null}
            </div>
            <div className="flex flex-wrap items-center justify-end gap-x-4 gap-y-2">
                <HoldedSyncStatus />
                {actions ? (
                    <div className="flex flex-wrap gap-2">{actions}</div>
                ) : null}
            </div>
        </header>
    );
}
