import { router } from '@inertiajs/react';
import {
    CalendarOff,
    CircleCheck,
    CircleDashed,
    CircleSlash,
    Clock,
    TreePalm,
    TriangleAlert,
} from 'lucide-react';
import { useId } from 'react';
import { NativeSelect } from '@/components/admin/native-select';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { DayPlanDayState } from '@/types/day-plan';

/**
 * Piezas de «Equipo hoy» y de la semana del equipo (D-251): el estado del día de una persona, con
 * texto e icono (nunca solo color), y los filtros por departamento y persona.
 */
export function DayStateBadge({
    state,
    reason,
    publishedAt,
    compact = false,
}: {
    state: DayPlanDayState;
    reason: string | null;
    /** Hora a la que escribió el plan (solo con las cifras). */
    publishedAt?: string | null;
    compact?: boolean;
}) {
    const config: Record<
        DayPlanDayState,
        { icon: typeof CircleCheck; label: string; tone: string }
    > = {
        plan: {
            icon: CircleCheck,
            label: publishedAt
                ? t('day_plan.state.plan_at', { time: formatTime(publishedAt) })
                : t('day_plan.state.plan'),
            tone: 'text-foreground',
        },
        no_plan: {
            icon: TriangleAlert,
            label: t('day_plan.state.no_plan'),
            tone: 'text-danger',
        },
        not_yet: {
            icon: Clock,
            label: t('day_plan.state.not_yet'),
            tone: 'text-muted-foreground',
        },
        away: {
            icon: TreePalm,
            label: reason ?? t('day_plan.state.away'),
            tone: 'text-muted-foreground',
        },
        holiday: {
            icon: CalendarOff,
            label: reason ?? t('day_plan.state.holiday'),
            tone: 'text-muted-foreground',
        },
        off: {
            icon: CircleSlash,
            label: t('day_plan.state.off'),
            tone: 'text-muted-foreground',
        },
        future: {
            icon: CircleDashed,
            label: t('day_plan.state.future'),
            tone: 'text-muted-foreground',
        },
    };
    const { icon: Icon, label, tone } = config[state];

    return (
        <span
            className={cn(
                'inline-flex min-w-0 items-center gap-1 text-xs',
                tone,
            )}
            data-test="day-plan-state"
            data-state={state}
        >
            <Icon
                aria-hidden="true"
                className={cn(
                    'size-3.5 shrink-0',
                    state === 'no_plan' && 'text-danger',
                )}
            />
            <span className={compact ? 'sr-only sm:not-sr-only' : undefined}>
                {label}
            </span>
        </span>
    );
}

/** Departamento (en la URL: ?departamento=ID|todos) y búsqueda de persona (en el navegador). */
export function TeamFilters({
    url,
    query,
    department,
    departments,
    search,
    onSearch,
}: {
    /** URL de la página sin el filtro de departamento. */
    url: string;
    /** Otros parámetros que se conservan (fecha, semana). */
    query: Record<string, string>;
    department: number | 'all';
    departments: { id: number; name: string }[];
    search: string;
    onSearch: (value: string) => void;
}) {
    const id = useId();

    return (
        <div
            className="flex flex-wrap items-end gap-3"
            data-test="day-plan-filters"
        >
            <div className="grid gap-1.5">
                <Label htmlFor={`${id}-department`}>
                    {t('day_plan.filters.department')}
                </Label>
                <NativeSelect
                    id={`${id}-department`}
                    className="w-56"
                    value={department === 'all' ? 'todos' : String(department)}
                    onChange={(event) =>
                        router.get(
                            url,
                            { ...query, departamento: event.target.value },
                            { preserveScroll: true, preserveState: true },
                        )
                    }
                    data-test="day-plan-department"
                >
                    <option value="todos">{t('day_plan.filters.all')}</option>
                    {departments.map((item) => (
                        <option key={item.id} value={String(item.id)}>
                            {item.name}
                        </option>
                    ))}
                </NativeSelect>
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor={`${id}-search`}>
                    {t('day_plan.filters.person')}
                </Label>
                <Input
                    id={`${id}-search`}
                    type="search"
                    className="w-56"
                    value={search}
                    placeholder={t('day_plan.filters.person_placeholder')}
                    onChange={(event) => onSearch(event.target.value)}
                    data-test="day-plan-search"
                />
            </div>
        </div>
    );
}
