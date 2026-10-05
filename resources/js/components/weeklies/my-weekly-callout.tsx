import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    ArrowRight,
    CalendarClock,
    CalendarOff,
    CircleAlert,
    CircleCheck,
    Clock,
    Minus,
    PencilLine,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { formatDate, formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as mySpaceIndex } from '@/routes/my-space';
import type { MyWeeklyStatus, WeeklyCycleSummary } from '@/types/weeklies';

type Variant = {
    tone: 'info' | 'warning' | 'success' | 'neutral' | 'danger';
    icon: LucideIcon;
    title: TranslationKey;
    description?: TranslationKey;
    action?: TranslationKey;
};

/** Qué decir de mi weekly según su estado (F-030 y F-032; AdminDashboard y MemberDashboard). */
function variantOf(me: MyWeeklyStatus): Variant {
    if (me.exemption_reason) {
        return {
            tone: 'neutral',
            icon: CalendarOff,
            title: `weeklies.callout.exempt.${me.exemption_reason}`,
            description: 'weeklies.exempt.streak',
            action: 'weeklies.callout.view',
        };
    }

    if (!me.participates || me.status === 'not_required') {
        return {
            tone: 'neutral',
            icon: Minus,
            title: 'weeklies.callout.not_required',
        };
    }

    switch (me.status) {
        case 'submitted':
            return {
                tone: 'success',
                icon: CircleCheck,
                title: 'weeklies.callout.submitted',
                description: 'weeklies.callout.submitted_hint',
                action: 'weeklies.callout.edit',
            };
        case 'submitted_late':
            return {
                tone: 'warning',
                icon: Clock,
                title: 'weeklies.callout.submitted_late',
                description: 'weeklies.callout.submitted_hint',
                action: 'weeklies.callout.edit',
            };
        case 'upcoming':
            return {
                tone: 'neutral',
                icon: CalendarClock,
                title: 'weeklies.callout.upcoming',
                description: 'weeklies.callout.upcoming_hint',
                action: 'weeklies.callout.start_early',
            };
        case 'overdue':
            return {
                tone: 'danger',
                icon: CircleAlert,
                title: 'weeklies.callout.overdue',
                description: 'weeklies.callout.overdue_hint',
                action: 'weeklies.callout.start',
            };
        case 'missed':
            return {
                tone: 'neutral',
                icon: Minus,
                title: 'weeklies.callout.missed',
            };
        default:
            return {
                tone: 'info',
                icon: PencilLine,
                title: 'weeklies.callout.pending',
                description: 'weeklies.callout.pending_hint',
                action: 'weeklies.callout.start',
            };
    }
}

const SURFACES: Record<Variant['tone'], { box: string; icon: string }> = {
    info: { box: 'border-primary/40 bg-info-soft', icon: 'text-info' },
    warning: { box: 'border-warning bg-warning-soft', icon: 'text-warning' },
    success: { box: 'border-success bg-success-soft', icon: 'text-success' },
    neutral: { box: 'bg-muted', icon: 'text-muted-foreground' },
    danger: { box: 'border-danger bg-danger-soft', icon: 'text-danger' },
};

/**
 * Estado de mi weekly de la semana activa con su botón (F-030 a F-032): «Tu weekly está
 * pendiente» → «Empezar mi weekly», «enviada» → «Editar», exenta («tu racha no se verá
 * afectada»)… Lo usan el resumen de /weeklies y la tarjeta de Inicio.
 */
export function MyWeeklyCallout({
    cycle,
    me,
    compact = false,
}: {
    cycle: WeeklyCycleSummary;
    me: MyWeeklyStatus;
    compact?: boolean;
}) {
    const variant = variantOf(me);
    const surface = SURFACES[variant.tone];
    const Icon = variant.icon;
    const draft =
        me.submitted_at === null && me.entries_count > 0 && me.draft_saved_at;

    return (
        <div
            className={cn(
                'flex flex-col gap-3 border p-4',
                !compact && 'md:flex-row md:items-center md:justify-between',
                surface.box,
            )}
            data-test="my-weekly-callout"
            data-status={me.status}
        >
            <div className="flex min-w-0 gap-3">
                <Icon
                    aria-hidden="true"
                    className={cn('mt-0.5 size-5 shrink-0', surface.icon)}
                    strokeWidth={1.5}
                />
                <div className="grid min-w-0 gap-1 text-sm">
                    <p className="font-medium text-foreground">
                        {t(variant.title)}
                    </p>
                    {variant.description ? (
                        <p className="text-foreground">
                            {t(variant.description, {
                                date: formatDate(cycle.deadline_date),
                            })}
                        </p>
                    ) : null}
                    {me.submitted_at ? (
                        <p className="text-xs text-muted-foreground">
                            {t('weeklies.editor.submitted_at', {
                                date: formatDateTime(me.submitted_at),
                            })}
                        </p>
                    ) : draft ? (
                        <p className="text-xs text-muted-foreground">
                            {t('weeklies.callout.draft', {
                                date: formatDateTime(me.draft_saved_at),
                            })}
                        </p>
                    ) : null}
                </div>
            </div>
            {variant.action ? (
                <Button
                    asChild
                    size="sm"
                    variant={
                        variant.tone === 'success' || variant.tone === 'neutral'
                            ? 'outline'
                            : 'default'
                    }
                    className="shrink-0 self-start md:self-center"
                >
                    <Link
                        href={mySpaceIndex.url({
                            query: { semana: cycle.id },
                        })}
                        data-test="my-weekly-open"
                    >
                        {t(variant.action)}
                        <ArrowRight aria-hidden="true" />
                    </Link>
                </Button>
            ) : null}
        </div>
    );
}
