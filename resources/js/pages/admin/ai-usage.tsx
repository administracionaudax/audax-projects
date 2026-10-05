import { Head, Link } from '@inertiajs/react';
import { CircleAlert, CircleCheck } from 'lucide-react';
import Heading from '@/components/heading';
import { StatusBadge } from '@/components/styleguide/status-badges';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDateTime, formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as adminIndex } from '@/routes/admin';
import { index as aiUsageIndex } from '@/routes/admin/ai-usage';
import type { AiUsagePageProps, AiUsageTotals } from '@/types/weeklies';

/** «0.049200» → «0,0492 $» (hasta 4 decimales; el coste exacto, en el título). */
export function formatUsd(value: string | null): string {
    if (value === null || value === '') {
        return '—';
    }

    return `${formatNumber(Number(value), 4)} $`;
}

function Kpi({ label, value }: { label: string; value: string }) {
    return (
        <div className="grid gap-1 border bg-card p-4">
            <dt className="text-xs tracking-wider text-muted-foreground uppercase">
                {label}
            </dt>
            <dd className="tabular text-2xl font-semibold">{value}</dd>
        </div>
    );
}

function TotalsCells({ row }: { row: AiUsageTotals }) {
    return (
        <>
            <TableCell className="tabular text-right">
                {formatNumber(row.calls, 0)}
            </TableCell>
            <TableCell className="tabular text-right">
                {formatNumber(row.errors, 0)}
            </TableCell>
            <TableCell className="tabular text-right">
                {formatNumber(row.total_tokens, 0)}
            </TableCell>
            <TableCell className="tabular text-right">
                {formatNumber(row.characters, 0)}
            </TableCell>
            <TableCell
                className="tabular text-right"
                title={`${row.cost_usd} USD`}
            >
                {formatUsd(row.cost_usd)}
            </TableCell>
        </>
    );
}

function TotalsHead({ first }: { first: string }) {
    return (
        <TableRow>
            <TableHead>{first}</TableHead>
            <TableHead className="text-right">{t('ai_usage.calls')}</TableHead>
            <TableHead className="text-right">{t('ai_usage.errors')}</TableHead>
            <TableHead className="text-right">{t('ai_usage.tokens')}</TableHead>
            <TableHead className="text-right">
                {t('ai_usage.characters')}
            </TableHead>
            <TableHead className="text-right">{t('ai_usage.cost')}</TableHead>
        </TableRow>
    );
}

/**
 * «Uso de IA» (F-173 y F-180, D-193): solo admins. Llamadas, errores, tokens, caracteres de
 * locución y coste estimado (USD) de los últimos 7, 30 o 90 días, por función y por modelo, el
 * coste por día y las últimas llamadas. Nunca enseña el texto enviado (no se guarda).
 */
export default function AiUsagePage({
    range,
    totals,
    by_feature,
    by_model,
    by_day,
    recent,
}: AiUsagePageProps) {
    const maxDay = Math.max(0, ...by_day.map((day) => Number(day.cost_usd)));

    return (
        <>
            <Head title={t('ai_usage.title')} />
            <div className="mx-auto flex w-full max-w-6xl min-w-0 flex-col gap-6 p-4 md:p-6">
                <Heading
                    as="h1"
                    title={t('ai_usage.title')}
                    description={t('ai_usage.description')}
                />

                <nav
                    aria-label={t('ai_usage.range')}
                    className="flex flex-wrap gap-2"
                >
                    {range.options.map((days) => (
                        <Link
                            key={days}
                            href={aiUsageIndex.url({ query: { dias: days } })}
                            aria-current={
                                days === range.days ? 'page' : undefined
                            }
                            className={cn(
                                'border px-3 py-1.5 text-sm',
                                days === range.days
                                    ? 'bg-primary text-primary-foreground'
                                    : 'hover:bg-accent',
                                FOCUS_RING,
                            )}
                            data-test="ai-usage-range"
                        >
                            {t('ai_usage.last_days', { days })}
                        </Link>
                    ))}
                </nav>

                <dl
                    className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
                    data-test="ai-usage-totals"
                >
                    <Kpi
                        label={t('ai_usage.calls')}
                        value={formatNumber(totals.calls, 0)}
                    />
                    <Kpi
                        label={t('ai_usage.errors')}
                        value={formatNumber(totals.errors, 0)}
                    />
                    <Kpi
                        label={t('ai_usage.tokens')}
                        value={formatNumber(totals.total_tokens, 0)}
                    />
                    <Kpi
                        label={t('ai_usage.cost')}
                        value={formatUsd(totals.cost_usd)}
                    />
                </dl>

                <section className="grid gap-3" aria-labelledby="ai-by-feature">
                    <h2 id="ai-by-feature" className="text-lg">
                        {t('ai_usage.by_feature')}
                    </h2>
                    <div className="overflow-x-auto border">
                        <Table>
                            <TableHeader>
                                <TotalsHead first={t('ai_usage.feature_col')} />
                            </TableHeader>
                            <TableBody>
                                {by_feature.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={6}
                                            className="text-muted-foreground"
                                        >
                                            {t('ai_usage.empty')}
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    by_feature.map((row) => (
                                        <TableRow key={row.feature}>
                                            <TableCell>
                                                {t(
                                                    `ai_usage.feature.${row.feature}`,
                                                )}
                                            </TableCell>
                                            <TotalsCells row={row} />
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </section>

                <section className="grid gap-3" aria-labelledby="ai-by-model">
                    <h2 id="ai-by-model" className="text-lg">
                        {t('ai_usage.by_model')}
                    </h2>
                    <div className="overflow-x-auto border">
                        <Table>
                            <TableHeader>
                                <TotalsHead first={t('ai_usage.model_col')} />
                            </TableHeader>
                            <TableBody>
                                {by_model.map((row) => (
                                    <TableRow
                                        key={`${row.provider}-${row.model}`}
                                    >
                                        <TableCell>
                                            {row.model}
                                            <span className="block text-xs text-muted-foreground">
                                                {t(
                                                    `ai_usage.provider.${row.provider}`,
                                                )}
                                            </span>
                                        </TableCell>
                                        <TotalsCells row={row} />
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </section>

                {by_day.length > 0 ? (
                    <section className="grid gap-3" aria-labelledby="ai-by-day">
                        <h2 id="ai-by-day" className="text-lg">
                            {t('ai_usage.by_day')}
                        </h2>
                        <ul className="grid gap-1" data-test="ai-usage-days">
                            {by_day.map((day) => (
                                <li
                                    key={day.date}
                                    className="grid grid-cols-[6rem_minmax(0,1fr)_7rem] items-center gap-3 text-sm"
                                >
                                    <span className="tabular text-muted-foreground">
                                        {day.date
                                            .split('-')
                                            .reverse()
                                            .join('/')}
                                    </span>
                                    <span className="h-2 bg-muted">
                                        <span
                                            className="block h-2 bg-chart-1"
                                            style={{
                                                width: `${maxDay > 0 ? (Number(day.cost_usd) / maxDay) * 100 : 0}%`,
                                            }}
                                        />
                                    </span>
                                    <span className="tabular text-right">
                                        {formatUsd(day.cost_usd)}
                                        <span className="sr-only">
                                            {` · ${t('ai_usage.calls_count', { count: day.calls })}`}
                                        </span>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                ) : null}

                <section className="grid gap-3" aria-labelledby="ai-recent">
                    <h2 id="ai-recent" className="text-lg">
                        {t('ai_usage.recent')}
                    </h2>
                    <div className="overflow-x-auto border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('ai_usage.when')}</TableHead>
                                    <TableHead>
                                        {t('ai_usage.feature_col')}
                                    </TableHead>
                                    <TableHead>
                                        {t('ai_usage.model_col')}
                                    </TableHead>
                                    <TableHead>{t('ai_usage.who')}</TableHead>
                                    <TableHead>
                                        {t('ai_usage.status')}
                                    </TableHead>
                                    <TableHead className="text-right">
                                        {t('ai_usage.tokens')}
                                    </TableHead>
                                    <TableHead className="text-right">
                                        {t('ai_usage.cost')}
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {recent.map((row) => (
                                    <TableRow
                                        key={row.id}
                                        data-test="ai-usage-row"
                                    >
                                        <TableCell className="tabular whitespace-nowrap">
                                            {formatDateTime(row.created_at)}
                                        </TableCell>
                                        <TableCell>
                                            {t(
                                                `ai_usage.feature.${row.feature}`,
                                            )}
                                            {row.operation ? (
                                                <span className="block text-xs text-muted-foreground">
                                                    {row.operation}
                                                </span>
                                            ) : null}
                                        </TableCell>
                                        <TableCell>{row.model}</TableCell>
                                        <TableCell>
                                            {row.user?.name ?? '—'}
                                        </TableCell>
                                        <TableCell>
                                            <StatusBadge
                                                tone={
                                                    row.status === 'error'
                                                        ? 'danger'
                                                        : 'success'
                                                }
                                                icon={
                                                    row.status === 'error'
                                                        ? CircleAlert
                                                        : CircleCheck
                                                }
                                            >
                                                {t(
                                                    `ai_usage.status_${row.status}`,
                                                )}
                                            </StatusBadge>
                                            {row.error ? (
                                                <span
                                                    className="block max-w-xs truncate text-xs text-muted-foreground"
                                                    title={row.error}
                                                >
                                                    {row.error}
                                                </span>
                                            ) : null}
                                        </TableCell>
                                        <TableCell className="tabular text-right">
                                            {row.total_tokens === null
                                                ? row.character_count === null
                                                    ? '—'
                                                    : t('ai_usage.chars', {
                                                          count: formatNumber(
                                                              row.character_count,
                                                              0,
                                                          ),
                                                      })
                                                : formatNumber(
                                                      row.total_tokens,
                                                      0,
                                                  )}
                                        </TableCell>
                                        <TableCell className="tabular text-right">
                                            {formatUsd(row.estimated_cost_usd)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </section>
            </div>
        </>
    );
}

AiUsagePage.layout = {
    breadcrumbs: [
        { title: t('nav.admin'), href: adminIndex() },
        { title: t('ai_usage.title'), href: aiUsageIndex() },
    ],
};
