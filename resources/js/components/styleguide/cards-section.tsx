import {
    ArrowDownRight,
    ArrowUpRight,
    CalendarDays,
    Clock,
} from 'lucide-react';
import { seriesColor } from '@/components/charts/chart-config';
import { Section, Specimen } from '@/components/styleguide/section';
import {
    TASK_STATUSES,
    TIMESHEET_STATUSES,
    TaskStatusBadge,
    TimesheetBadge,
} from '@/components/styleguide/status-badges';
import type {
    TaskStatus,
    TimesheetStatus,
} from '@/components/styleguide/status-badges';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatDate, formatMinutes, formatPercent } from '@/lib/format';
import { cn } from '@/lib/utils';

const KPIS = [
    {
        label: 'Horas imputadas',
        value: formatMinutes(20_310),
        delta: 0.042,
        up: 'good' as const,
        period: 'frente a agosto',
    },
    {
        label: 'Ocupación',
        value: formatPercent(0.83, 0),
        delta: -0.03,
        up: 'good' as const,
        period: 'frente a agosto',
    },
    {
        label: 'Tareas vencidas',
        value: '7',
        delta: 0.4,
        up: 'bad' as const,
        period: 'frente a agosto',
    },
];

const DEPARTMENTS = ['Diseño', 'Desarrollo', 'Marketing'];

export function CardsSection() {
    return (
        <Section
            id="tarjetas-y-badges"
            title="Tarjetas y badges"
            description="Separación con bordes finos y surface-muted, sin sombras marcadas. Los estados siempre llevan icono y texto."
        >
            <Specimen title="Indicadores (KPI)" className="border-0 p-0 sm:p-0">
                <div className="grid gap-4 sm:grid-cols-3">
                    {KPIS.map((kpi) => {
                        const good =
                            kpi.up === 'good' ? kpi.delta >= 0 : kpi.delta <= 0;
                        const Arrow =
                            kpi.delta >= 0 ? ArrowUpRight : ArrowDownRight;

                        return (
                            <Card
                                key={kpi.label}
                                className="gap-2 rounded-[3px] py-4 shadow-none"
                            >
                                <CardContent className="grid gap-1 px-4">
                                    <p className="text-sm text-muted-foreground">
                                        {kpi.label}
                                    </p>
                                    <p className="text-3xl">{kpi.value}</p>
                                    <p className="flex items-center gap-1 text-xs text-muted-foreground">
                                        <span
                                            className={cn(
                                                'inline-flex items-center gap-0.5 font-medium',
                                                good
                                                    ? 'text-success'
                                                    : 'text-danger',
                                            )}
                                        >
                                            <Arrow
                                                aria-hidden="true"
                                                className="size-3.5"
                                            />
                                            {kpi.delta > 0 ? '+' : ''}
                                            {formatPercent(kpi.delta, 0)}
                                        </span>
                                        {kpi.period}
                                    </p>
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>
            </Specimen>

            <Specimen
                title="Tarjeta de proyecto"
                className="border-0 p-0 sm:p-0"
            >
                <Card className="max-w-md gap-4 rounded-[3px] py-5 shadow-none">
                    <CardHeader className="px-5">
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <CardTitle className="text-lg leading-snug font-normal">
                                    Web Hoteles Mediterráneo
                                </CardTitle>
                                <CardDescription>
                                    Hoteles Mediterráneo S. L. · Bolsa de horas
                                </CardDescription>
                            </div>
                            <TaskStatusBadge status="in_progress" />
                        </div>
                    </CardHeader>
                    <CardContent className="grid gap-3 px-5 text-sm">
                        <div className="flex flex-wrap gap-x-4 gap-y-1 text-muted-foreground">
                            <span className="inline-flex items-center gap-1.5">
                                <Clock aria-hidden="true" className="size-4" />
                                <span className="tabular">
                                    {formatMinutes(3150)}
                                </span>{' '}
                                imputadas
                            </span>
                            <span className="inline-flex items-center gap-1.5">
                                <CalendarDays
                                    aria-hidden="true"
                                    className="size-4"
                                />
                                Entrega el {formatDate('2026-10-30')}
                            </span>
                        </div>
                        <div className="flex flex-wrap gap-1.5">
                            {DEPARTMENTS.map((name, index) => (
                                <span
                                    key={name}
                                    className="inline-flex items-center gap-1.5 rounded-[3px] border px-1.5 py-0.5 text-xs"
                                >
                                    <span
                                        aria-hidden="true"
                                        className="size-2 rounded-full"
                                        style={{
                                            backgroundColor: seriesColor(index),
                                        }}
                                    />
                                    {name}
                                </span>
                            ))}
                        </div>
                    </CardContent>
                </Card>
            </Specimen>

            <Specimen title="Estados de tarea">
                <div className="flex flex-wrap gap-2">
                    {(Object.keys(TASK_STATUSES) as TaskStatus[]).map(
                        (status) => (
                            <TaskStatusBadge key={status} status={status} />
                        ),
                    )}
                </div>
            </Specimen>

            <Specimen title="Estados de la hoja semanal">
                <div className="flex flex-wrap gap-2">
                    {(Object.keys(TIMESHEET_STATUSES) as TimesheetStatus[]).map(
                        (status) => (
                            <TimesheetBadge key={status} status={status} />
                        ),
                    )}
                </div>
            </Specimen>

            <Specimen
                title="Badges genéricos"
                note="Para etiquetas sin significado de estado (tipo, prioridad, recuento)."
            >
                <div className="flex flex-wrap gap-2">
                    <Badge className="rounded-[3px]">Nuevo</Badge>
                    <Badge variant="secondary" className="rounded-[3px]">
                        Maquetación
                    </Badge>
                    <Badge variant="outline" className="rounded-[3px]">
                        Prioridad alta
                    </Badge>
                    <Badge variant="outline" className="tabular rounded-[3px]">
                        12 tareas
                    </Badge>
                </div>
            </Specimen>
        </Section>
    );
}
