import { router } from '@inertiajs/react';
import { toastVisitErrors } from '@/components/admin/visit-errors';
import { CalendarPlus } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { addDays, todayInMadrid } from '@/lib/week';

/**
 * «Añadir a mi día» (y «a mañana») de Mis tareas (docs/PLAN-CARGAS.md §10; D-254): una línea del plan
 * con el título de la tarea y la tarea enlazada. Si ya estaba ese día, no se repite.
 */
export function AddToMyDayButton({
    task,
}: {
    task: { id: number; title: string };
}) {
    const [processing, setProcessing] = useState(false);
    const today = todayInMadrid();

    const add = (date: string) =>
        router.post(
            urls.dayPlanFromTasks(),
            { date, task_ids: [task.id] },
            {
                preserveScroll: true,
                preserveState: true,
                errorBag: 'dayPlan',
                // Que ningún error del servidor se pierda en silencio (D-310).
                onError: toastVisitErrors,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-8"
                    disabled={processing}
                    aria-label={t('day_plan.add_to_day.label', {
                        task: task.title,
                    })}
                    title={t('day_plan.add_to_day.title')}
                    data-test="add-to-my-day"
                >
                    {processing ? (
                        <Spinner />
                    ) : (
                        <CalendarPlus aria-hidden="true" />
                    )}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem
                    onSelect={() => add(today)}
                    data-test="add-to-my-day-today"
                >
                    {t('day_plan.add_to_day.today')}
                </DropdownMenuItem>
                <DropdownMenuItem
                    onSelect={() => add(addDays(today, 1))}
                    data-test="add-to-my-day-tomorrow"
                >
                    {t('day_plan.add_to_day.tomorrow')}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
