import type { LucideIcon } from 'lucide-react';
import {
    Archive,
    ArrowDown,
    ArrowUp,
    Ban,
    CircleAlert,
    CircleCheck,
    CircleDot,
    CirclePause,
    CircleSlash,
    Clock,
    Lock,
    Minus,
    PencilLine,
    RefreshCw,
    Send,
    Siren,
    Undo2,
} from 'lucide-react';
import { StatusBadge } from '@/components/styleguide/status-badges';
import { t } from '@/lib/i18n';
import type {
    HourBankStatus,
    ProjectStatus,
    TaskPriority,
    TimeEntryStatus,
    TimesheetStatus,
} from '@/types';

/**
 * Etiquetas de estado de las entidades de la Fase 1: fondo suave, icono con el color de estado
 * y texto en tinta de texto. Nunca solo color (SPEC §3.1).
 */

type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'danger';
type Meta = { tone: Tone; icon: LucideIcon };

const PROJECT: Record<ProjectStatus, Meta> = {
    planned: { tone: 'neutral', icon: Clock },
    active: { tone: 'info', icon: CircleDot },
    on_hold: { tone: 'warning', icon: CirclePause },
    completed: { tone: 'success', icon: CircleCheck },
    archived: { tone: 'neutral', icon: Archive },
};

export function ProjectStatusBadge({ status }: { status: ProjectStatus }) {
    const meta = PROJECT[status];

    return (
        <StatusBadge tone={meta.tone} icon={meta.icon}>
            {t(`project.status.${status}`)}
        </StatusBadge>
    );
}

const HOUR_BANK: Record<HourBankStatus, Meta> = {
    active: { tone: 'success', icon: CircleCheck },
    exhausted: { tone: 'danger', icon: CircleAlert },
    closed: { tone: 'neutral', icon: CircleSlash },
    renewed: { tone: 'neutral', icon: RefreshCw },
};

export function HourBankStatusBadge({ status }: { status: HourBankStatus }) {
    const meta = HOUR_BANK[status];

    return (
        <StatusBadge tone={meta.tone} icon={meta.icon}>
            {t(`hour_bank.status.${status}`)}
        </StatusBadge>
    );
}

const PRIORITY: Record<TaskPriority, Meta> = {
    low: { tone: 'neutral', icon: ArrowDown },
    normal: { tone: 'neutral', icon: Minus },
    high: { tone: 'warning', icon: ArrowUp },
    urgent: { tone: 'danger', icon: Siren },
};

export function PriorityBadge({ priority }: { priority: TaskPriority }) {
    const meta = PRIORITY[priority];

    return (
        <StatusBadge tone={meta.tone} icon={meta.icon}>
            {t(`task.priority.${priority}`)}
        </StatusBadge>
    );
}

const TIME_ENTRY: Record<TimeEntryStatus, Meta> = {
    draft: { tone: 'neutral', icon: PencilLine },
    submitted: { tone: 'info', icon: Send },
    approved: { tone: 'success', icon: CircleCheck },
    locked: { tone: 'neutral', icon: Lock },
};

export function TimeEntryStatusBadge({ status }: { status: TimeEntryStatus }) {
    const meta = TIME_ENTRY[status];

    return (
        <StatusBadge tone={meta.tone} icon={meta.icon}>
            {t(`time_entry.status.${status}`)}
        </StatusBadge>
    );
}

const TIMESHEET: Record<TimesheetStatus, Meta> = {
    open: { tone: 'neutral', icon: PencilLine },
    submitted: { tone: 'info', icon: Send },
    returned: { tone: 'warning', icon: Undo2 },
    approved: { tone: 'success', icon: CircleCheck },
    locked: { tone: 'neutral', icon: Lock },
};

export function TimesheetStatusBadge({ status }: { status: TimesheetStatus }) {
    const meta = TIMESHEET[status];

    return (
        <StatusBadge tone={meta.tone} icon={meta.icon}>
            {t(`timesheet.status.${status}`)}
        </StatusBadge>
    );
}

/** Estado de tarea configurable: color del admin en el punto, nombre en tinta de texto. */
export function TaskStatusBadge({
    name,
    color,
    done,
}: {
    name: string;
    color: string;
    done?: boolean;
}) {
    return (
        <span className="inline-flex items-center gap-1.5 rounded-[3px] bg-neutral-soft px-1.5 py-0.5 text-xs font-medium whitespace-nowrap text-foreground">
            {done ? (
                <CircleCheck
                    aria-hidden="true"
                    className="size-3.5 shrink-0 text-success"
                />
            ) : (
                <span
                    aria-hidden="true"
                    className="size-2 shrink-0 rounded-full"
                    style={{ backgroundColor: color }}
                />
            )}
            {name}
        </span>
    );
}

export { Ban as BlockedIcon };
