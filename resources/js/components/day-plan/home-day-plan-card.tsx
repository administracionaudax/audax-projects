import { Link, router } from '@inertiajs/react';
import { Sun } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { EmptyState } from '@/components/empty-state';
import { Checkbox } from '@/components/ui/checkbox';
import { Skeleton } from '@/components/ui/skeleton';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { show } from '@/routes/day-plan';
import { status } from '@/routes/day-plan/items';
import { LineComposer } from './line-composer';
import { LineContent } from './line-content';
import type { DayPlanLine, HomeDayPlanCard as Card } from '@/types/day-plan';

/** Líneas que caben en la tarjeta; el resto, en Mi día. */
export const HOME_LINES = 6;

/**
 * Tarjeta «Mi día» de Inicio (docs/PLAN-CARGAS.md §4.3): mis líneas de hoy con su check, el
 * temporizador de cada una (`renderTimer`), «Añadir línea…» con Intro y el enlace a Mi día. Si hay
 * pendientes de días anteriores, lo recuerda con un enlace.
 */
export function HomeDayPlanCard({
    card,
    renderTimer,
}: {
    card: Card;
    renderTimer?: (line: DayPlanLine) => ReactNode;
}) {
    const visible = card.items.slice(0, HOME_LINES);
    const hidden = card.items.length - visible.length;

    return (
        <div className="grid gap-3" data-test="home-day-plan">
            <p
                className="text-sm text-muted-foreground"
                data-test="home-day-plan-progress"
            >
                {card.total === 0
                    ? t('day_plan.home.no_lines', { time: card.deadline })
                    : t('day_plan.home.progress', {
                          done: card.done,
                          total: card.total,
                      })}
            </p>
            {card.pending > 0 ? (
                <Link
                    href={show.url()}
                    className={cn(
                        'self-start text-sm text-primary-text hover:underline',
                        FOCUS_RING,
                    )}
                    data-test="home-day-plan-pending"
                >
                    {t('day_plan.home.pending', { count: card.pending })}
                </Link>
            ) : null}
            {visible.length === 0 && !card.can_write ? (
                <EmptyState icon={Sun} title={t('day_plan.home.empty')} />
            ) : null}
            {visible.length > 0 ? (
                <ul className="divide-y">
                    {visible.map((line) => (
                        <HomeLine
                            key={line.id}
                            line={line}
                            timer={renderTimer?.(line)}
                        />
                    ))}
                </ul>
            ) : null}
            {hidden > 0 ? (
                <p className="text-xs text-muted-foreground">
                    {t('day_plan.home.more', { count: hidden })}
                </p>
            ) : null}
            {card.can_write ? (
                <LineComposer date={card.date} targets={undefined} compact />
            ) : null}
            <Link
                href={show.url()}
                className={cn(
                    'self-start text-sm text-primary-text hover:underline',
                    FOCUS_RING,
                )}
            >
                {t('day_plan.home.open')}
            </Link>
        </div>
    );
}

function HomeLine({ line, timer }: { line: DayPlanLine; timer?: ReactNode }) {
    const [processing, setProcessing] = useState(false);
    const carried = line.status === 'carried';

    return (
        <li
            className="flex items-start gap-2 py-2"
            data-test="home-day-plan-line"
        >
            <Checkbox
                checked={line.status === 'done'}
                disabled={carried || processing}
                onCheckedChange={(checked) =>
                    router.post(
                        status.url(line.id),
                        { status: checked === true ? 'done' : 'pending' },
                        {
                            preserveScroll: true,
                            preserveState: true,
                            errorBag: 'dayPlan',
                            only: ['day_plan'],
                            onStart: () => setProcessing(true),
                            onFinish: () => setProcessing(false),
                        },
                    )
                }
                aria-label={t(
                    line.status === 'done'
                        ? 'day_plan.line.uncheck'
                        : 'day_plan.line.check',
                    { text: line.text },
                )}
                className="mt-0.5"
            />
            <LineContent line={line} />
            {timer}
        </li>
    );
}

export function HomeDayPlanSkeleton() {
    return (
        <div className="grid gap-2" aria-hidden="true">
            <Skeleton className="h-4 w-32" />
            <Skeleton className="h-6 w-full" />
            <Skeleton className="h-6 w-full" />
        </div>
    );
}
