import { Head, router } from '@inertiajs/react';
import { CircleAlert, SearchX, TriangleAlert, Wallet, X } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useId } from 'react';
import { EmptyState } from '@/components/empty-state';
import { useHourBankThresholds } from '@/components/hour-banks/hour-bank-actions';
import { HourBankHistory } from '@/components/hour-banks/hour-bank-history';
import { HourBanksOverviewTable } from '@/components/hour-banks/hour-banks-overview-table';
import { overviewQuery } from '@/components/hour-banks/overview-query';
import { FilterSelect } from '@/components/projects-list/filter-select';
import { ListPagination } from '@/components/projects-list/list-pagination';
import { PageHeader } from '@/components/projects-list/page-header';
import { PageSection } from '@/components/projects-list/page-section';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { t } from '@/lib/i18n';
import { index } from '@/routes/hour-banks';
import type {
    HourBankOverviewFilters,
    HourBanksIndexProps,
    HourBankStatus,
} from '@/types';

const STATUSES: HourBankStatus[] = ['active', 'exhausted', 'closed', 'renewed'];

/**
 * Vista global de bolsas (SPEC §8): las abiertas de todos los clientes, de más a menos consumida,
 * para anticipar renovaciones y facturación. Un gestor ve solo las de sus proyectos (D-035).
 * Con un cliente elegido, también el histórico de renovaciones de ese cliente (SPEC §8.8).
 */
export default function HourBanksIndex({
    banks,
    filters,
    stats,
    threshold,
    scope,
    history,
    options,
}: HourBanksIndexProps) {
    const id = useId();
    const thresholds = useHourBankThresholds();
    const filtered = Object.keys(overviewQuery(filters)).length > 0;
    const client = options.clients.find(
        (option) => option.id === filters.cliente,
    );

    const apply = (next: HourBankOverviewFilters) =>
        router.get(index.url({ query: overviewQuery(next) }), undefined, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });

    const set = <K extends keyof HourBankOverviewFilters>(
        key: K,
        value: HourBankOverviewFilters[K],
    ) => apply({ ...filters, [key]: value });

    return (
        <>
            <Head title={t('hour_banks.overview.title')} />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('hour_banks.overview.heading')}
                    description={t(
                        scope === 'managed'
                            ? 'hour_banks.overview.description_managed'
                            : 'hour_banks.overview.description',
                    )}
                />

                <dl className="grid grid-cols-3 gap-2 sm:gap-3">
                    <Stat
                        icon={Wallet}
                        label={t('hour_banks.overview.stat_open')}
                        value={stats.open}
                    />
                    <Stat
                        icon={TriangleAlert}
                        tone="text-warning"
                        label={t('hour_banks.overview.stat_near', {
                            threshold,
                        })}
                        value={stats.near}
                    />
                    <Stat
                        icon={CircleAlert}
                        tone="text-danger"
                        label={t('hour_banks.overview.stat_exhausted')}
                        value={stats.exhausted}
                    />
                </dl>

                <div
                    role="search"
                    aria-label={t('hour_banks.overview.filters')}
                    className="grid gap-3 rounded-md border bg-card p-3"
                >
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <FilterSelect
                            id={`${id}-client`}
                            label={t('projects.filters.client')}
                            allLabel={t('projects.filters.all_clients')}
                            value={
                                filters.cliente === null
                                    ? null
                                    : String(filters.cliente)
                            }
                            options={options.clients.map((client) => ({
                                value: String(client.id),
                                label: client.name,
                            }))}
                            onChange={(value) =>
                                set(
                                    'cliente',
                                    value === null ? null : Number(value),
                                )
                            }
                        />
                        <FilterSelect
                            id={`${id}-department`}
                            label={t('hour_banks.overview.department_filter')}
                            allLabel={t('projects.filters.all_departments')}
                            value={
                                filters.departamento === null
                                    ? null
                                    : String(filters.departamento)
                            }
                            options={options.departments.map((department) => ({
                                value: String(department.id),
                                label: department.name,
                            }))}
                            onChange={(value) =>
                                set(
                                    'departamento',
                                    value === null ? null : Number(value),
                                )
                            }
                        />
                        <FilterSelect
                            id={`${id}-status`}
                            label={t('hour_banks.overview.status')}
                            allLabel={t('hour_banks.overview.open_only')}
                            value={
                                filters.estado === '' ? null : filters.estado
                            }
                            options={[
                                ...STATUSES.map((status) => ({
                                    value: status,
                                    label: t(`hour_bank.status.${status}`),
                                })),
                                {
                                    value: 'todas',
                                    label: t(
                                        'hour_banks.overview.all_statuses',
                                    ),
                                },
                            ]}
                            onChange={(value) =>
                                set(
                                    'estado',
                                    (value ??
                                        '') as HourBankOverviewFilters['estado'],
                                )
                            }
                        />
                    </div>
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-center gap-2">
                            <Switch
                                id={`${id}-near`}
                                checked={filters.proximas}
                                onCheckedChange={(checked) =>
                                    set('proximas', checked)
                                }
                            />
                            <Label
                                htmlFor={`${id}-near`}
                                className="font-normal"
                            >
                                {t('hour_banks.overview.near_only', {
                                    threshold,
                                })}
                            </Label>
                        </div>
                        {filtered ? (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                    apply({
                                        cliente: null,
                                        departamento: null,
                                        estado: '',
                                        proximas: false,
                                    })
                                }
                            >
                                <X aria-hidden="true" />
                                {t('projects.filters.clear')}
                            </Button>
                        ) : null}
                    </div>
                </div>

                {banks.data.length === 0 ? (
                    <EmptyState
                        icon={filtered ? SearchX : Wallet}
                        title={t(
                            filtered
                                ? 'hour_banks.overview.no_results'
                                : 'hour_banks.overview.empty',
                        )}
                        description={t(
                            filtered
                                ? 'hour_banks.overview.no_results_description'
                                : 'hour_banks.overview.empty_description',
                        )}
                    />
                ) : (
                    <HourBanksOverviewTable
                        banks={banks.data}
                        thresholds={thresholds}
                    />
                )}

                <ListPagination
                    page={banks}
                    label={t('hour_banks.overview.pages')}
                />

                {history ? (
                    <PageSection
                        title={t('hour_banks.overview.history_heading', {
                            client: client?.name ?? '',
                        })}
                        description={t(
                            'hour_banks.overview.history_description',
                        )}
                    >
                        <HourBankHistory
                            chains={history.chains}
                            projects={history.projects}
                            emptyDescription={t(
                                'hour_banks.overview.history_empty_description',
                            )}
                        />
                    </PageSection>
                ) : null}
            </div>
        </>
    );
}

HourBanksIndex.layout = {
    breadcrumbs: [{ title: t('nav.hour_banks'), href: index() }],
};

function Stat({
    icon: Icon,
    tone = 'text-muted-foreground',
    label,
    value,
}: {
    icon: LucideIcon;
    tone?: string;
    label: string;
    value: number;
}) {
    return (
        <div className="grid content-start gap-1 rounded-md border bg-card p-3 sm:p-4">
            <dt className="flex items-start gap-1.5 text-xs text-muted-foreground">
                <Icon
                    aria-hidden="true"
                    className={`mt-px size-3.5 shrink-0 ${tone}`}
                />
                {label}
            </dt>
            <dd className="tabular text-xl sm:text-2xl">{value}</dd>
        </div>
    );
}
