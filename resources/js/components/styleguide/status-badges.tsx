import type { LucideIcon } from 'lucide-react';
import {
    Ban,
    Circle,
    CircleCheck,
    CircleDot,
    Eye,
    Lock,
    PencilLine,
    Send,
    Undo2,
} from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Badges de estado: fondo suave, texto en tinta de texto y el color de estado en el icono.
 * Nunca solo color (SPEC §3.1): icono + etiqueta siempre.
 */

type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'danger';

const TONES: Record<Tone, { surface: string; icon: string }> = {
    neutral: { surface: 'bg-neutral-soft', icon: 'text-muted-foreground' },
    info: { surface: 'bg-info-soft', icon: 'text-info' },
    success: { surface: 'bg-success-soft', icon: 'text-success' },
    warning: { surface: 'bg-warning-soft', icon: 'text-warning' },
    danger: { surface: 'bg-danger-soft', icon: 'text-danger' },
};

export function StatusBadge({
    tone,
    icon: Icon,
    children,
    className,
}: {
    tone: Tone;
    icon: LucideIcon;
    children: string;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium whitespace-nowrap text-foreground',
                TONES[tone].surface,
                className,
            )}
        >
            <Icon
                aria-hidden="true"
                className={cn('size-3.5 shrink-0', TONES[tone].icon)}
            />
            {children}
        </span>
    );
}

export type TaskStatus = 'todo' | 'in_progress' | 'review' | 'blocked' | 'done';

export const TASK_STATUSES: Record<
    TaskStatus,
    { label: string; tone: Tone; icon: LucideIcon }
> = {
    todo: { label: 'Por hacer', tone: 'neutral', icon: Circle },
    in_progress: { label: 'En curso', tone: 'info', icon: CircleDot },
    review: { label: 'En revisión', tone: 'info', icon: Eye },
    blocked: { label: 'Bloqueada', tone: 'danger', icon: Ban },
    done: { label: 'Hecha', tone: 'success', icon: CircleCheck },
};

export function TaskStatusBadge({ status }: { status: TaskStatus }) {
    const meta = TASK_STATUSES[status];

    return (
        <StatusBadge tone={meta.tone} icon={meta.icon}>
            {meta.label}
        </StatusBadge>
    );
}

export type TimesheetStatus =
    | 'open'
    | 'submitted'
    | 'approved'
    | 'returned'
    | 'locked';

export const TIMESHEET_STATUSES: Record<
    TimesheetStatus,
    { label: string; tone: Tone; icon: LucideIcon }
> = {
    open: { label: 'Abierta', tone: 'neutral', icon: PencilLine },
    submitted: { label: 'Enviada', tone: 'info', icon: Send },
    approved: { label: 'Aprobada', tone: 'success', icon: CircleCheck },
    returned: { label: 'Devuelta', tone: 'warning', icon: Undo2 },
    locked: { label: 'Bloqueada', tone: 'neutral', icon: Lock },
};

export function TimesheetBadge({ status }: { status: TimesheetStatus }) {
    const meta = TIMESHEET_STATUSES[status];

    return (
        <StatusBadge tone={meta.tone} icon={meta.icon}>
            {meta.label}
        </StatusBadge>
    );
}
