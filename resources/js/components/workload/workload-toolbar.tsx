import { X } from 'lucide-react';
import { useId } from 'react';
import { MultiSelectFilter } from '@/components/reports/multi-select-filter';
import type { FilterOption } from '@/components/reports/multi-select-filter';
import type {
    WorkloadFilterKey,
    WorkloadFiltersProps,
    WorkloadHorizon,
    WorkloadHorizonKey,
    WorkloadOptions,
    WorkloadPerson,
    WorkloadQuery,
} from '@/components/workload/types';
import { horizonLabel } from '@/components/workload/workload-labels';
import { Button } from '@/components/ui/button';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';

/** Filtros que acotan las filas (solo quien ve a su equipo) y los que acotan las tareas. */
const PEOPLE_FILTERS: WorkloadFilterKey[] = ['departamento', 'persona'];
const TASK_FILTERS: WorkloadFilterKey[] = ['cliente', 'proyecto'];

/** Quita las claves vacías: la URL queda limpia y se puede compartir. */
export function cleanQuery(query: WorkloadQuery): WorkloadQuery {
    const next: WorkloadQuery = { horizonte: query.horizonte };

    for (const key of [...PEOPLE_FILTERS, ...TASK_FILTERS]) {
        const ids = query[key];

        if (ids && ids.length > 0) {
            next[key] = ids;
        }
    }

    return next;
}

/**
 * Horizonte y filtros de la vista Carga (D-052). Todo vive en la URL (?horizonte=…&departamento[]=…
 * &persona[]=…&cliente[]=…&proyecto[]=…): cada cambio es una visita a la misma página. Quien solo
 * ve su fila no tiene filtros de persona ni de departamento.
 */
export function WorkloadToolbar({
    horizon,
    filters,
    options,
    people,
    onChange,
}: {
    horizon: WorkloadHorizon;
    filters: WorkloadFiltersProps;
    options: WorkloadOptions;
    people: WorkloadPerson[];
    onChange: (query: WorkloadQuery) => void;
}) {
    const id = useId();
    const query = filters.query;
    const shown = filters.sees_team
        ? [...PEOPLE_FILTERS, ...TASK_FILTERS]
        : TASK_FILTERS;
    const hasFilters = shown.some((key) => (query[key]?.length ?? 0) > 0);
    const selectedClients = query.cliente ?? [];

    const update = (patch: Partial<WorkloadQuery>) =>
        onChange(cleanQuery({ ...query, ...patch }));

    const lists: Record<WorkloadFilterKey, FilterOption[]> = {
        departamento: options.departments,
        persona: people.map((person) => ({
            id: person.id,
            name: person.department
                ? `${person.name} · ${person.department}`
                : person.name,
        })),
        cliente: options.clients,
        // Con clientes elegidos, primero sus proyectos (el resto se puede elegir igualmente).
        proyecto: [...options.projects]
            .sort(
                (a, b) =>
                    Number(!selectedClients.includes(a.client_id ?? -1)) -
                    Number(!selectedClients.includes(b.client_id ?? -1)),
            )
            .map((project) => ({
                id: project.id,
                name: project.name,
                muted:
                    selectedClients.length > 0 &&
                    !selectedClients.includes(project.client_id ?? -1),
            })),
    };

    return (
        <section
            aria-label={t('workload_filters.label')}
            className="grid gap-3 rounded-md border bg-card p-3"
            data-test="workload-toolbar"
        >
            <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                <div
                    className="flex flex-wrap items-center gap-2"
                    role="group"
                    aria-labelledby={`${id}-horizon`}
                >
                    <span id={`${id}-horizon`} className="text-sm">
                        {t('workload_horizon.label')}
                    </span>
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        value={horizon.key}
                        onValueChange={(value) => {
                            if (value && value !== horizon.key) {
                                update({
                                    horizonte: value as WorkloadHorizonKey,
                                });
                            }
                        }}
                        className="flex flex-wrap"
                    >
                        {horizon.options.map((key) => (
                            <ToggleGroupItem
                                key={key}
                                value={key}
                                className="px-3"
                            >
                                {horizonLabel(key)}
                            </ToggleGroupItem>
                        ))}
                    </ToggleGroup>
                </div>
                <p
                    className="text-sm text-muted-foreground"
                    aria-live="polite"
                    data-test="workload-range"
                >
                    {t('workload_page.range', {
                        from: formatDate(horizon.from),
                        to: formatDate(horizon.to),
                    })}
                    {horizon.by_week ? ` · ${t('workload_page.by_week')}` : ''}
                </p>
            </div>

            <div className="flex flex-wrap items-center gap-2">
                {shown.map((key) => (
                    <MultiSelectFilter
                        key={key}
                        label={t(`workload_filters.${key}`)}
                        options={lists[key]}
                        value={query[key] ?? []}
                        onChange={(ids) => update({ [key]: ids })}
                    />
                ))}

                {hasFilters ? (
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => onChange({ horizonte: query.horizonte })}
                    >
                        <X aria-hidden="true" />
                        {t('workload_filters.clear')}
                    </Button>
                ) : null}
            </div>

            {(query.cliente?.length ?? 0) > 0 ||
            (query.proyecto?.length ?? 0) > 0 ? (
                <p className="text-xs text-muted-foreground">
                    {t('workload_filters.tasks_hint')}
                </p>
            ) : null}
        </section>
    );
}
