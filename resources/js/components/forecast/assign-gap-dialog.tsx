import { router } from '@inertiajs/react';
import { toastVisitErrors } from '@/components/admin/visit-errors';
import { TriangleAlert, UserPlus } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import type { WorkloadPerson } from '@/components/workload/types';
import { PersonLoadPicker } from '@/components/workload/person-load-picker';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { loadPercent } from '@/components/charts/thresholds';
import {
    allocationAmountLabel,
    dateRange,
    formatHours,
    formatPercentValue,
} from '@/lib/forecast';
import { t } from '@/lib/i18n';
import { availability } from '@/routes/forecast';
import { assign } from '@/routes/forecast/allocations';
import type {
    Allocation,
    AvailabilityPerson,
    PersonOption,
} from '@/types/forecast';

/** Pide la carga de cada persona asignable en unas fechas (D-303). Exportada para los tests. */
export async function fetchAvailability(
    from: string,
    to: string,
    signal?: AbortSignal,
): Promise<AvailabilityPerson[]> {
    const response = await fetch(
        availability.url({ query: { desde: from, hasta: to } }),
        {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            signal,
        },
    );

    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }

    const data = (await response.json()) as { people?: AvailabilityPerson[] };

    return Array.isArray(data.people) ? data.people : [];
}

function addMonths(date: string, months: number): string {
    const value = new Date(`${date}T00:00:00Z`);
    value.setUTCMonth(value.getUTCMonth() + months);

    return value.toISOString().slice(0, 10);
}

/**
 * «Asignar a…» un hueco sin persona (D-293 y D-303): elige a alguien con su ocupación en las fechas
 * del hueco (como PersonLoadPicker en la Carga) y avisa si se pasaría del 100 %. Quien no ve la
 * previsión global (un gestor de proyecto) elige solo por nombre, sin la carga de nadie (P4).
 */
export function AssignGapDialog({
    allocation,
    departmentName,
    open,
    onOpenChange,
    people,
    showLoad = true,
}: {
    allocation: Allocation;
    departmentName: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Sin carga: la lista de personas de las opciones. */
    people?: PersonOption[];
    showLoad?: boolean;
}) {
    const id = useId();
    const [candidates, setCandidates] = useState<AvailabilityPerson[] | null>(
        null,
    );
    const [failed, setFailed] = useState(false);
    const [userId, setUserId] = useState<number | null>(null);
    const [saving, setSaving] = useState(false);
    const to = allocation.end_date ?? addMonths(allocation.start_date, 3);

    useEffect(() => {
        if (!open || !showLoad) {
            return;
        }

        const controller = new AbortController();
        setFailed(false);
        fetchAvailability(allocation.start_date, to, controller.signal)
            .then(setCandidates)
            .catch(() => {
                if (!controller.signal.aborted) {
                    setFailed(true);
                }
            });

        return () => controller.abort();
    }, [open, showLoad, allocation.start_date, to]);

    const team: WorkloadPerson[] = showLoad
        ? (candidates ?? []).map((person) => ({
              id: person.id,
              name: person.name,
              department_id: person.department_id,
              department: person.collaborator
                  ? t('forecast.matrix.collaborators')
                  : null,
              planned: person.load,
              capacity: person.has_schedule ? person.capacity : 0,
              is_me: false,
          }))
        : [];
    // Las del departamento del hueco, primero.
    team.sort(
        (a, b) =>
            Number(b.department_id === allocation.department?.id) -
                Number(a.department_id === allocation.department?.id) ||
            a.name.localeCompare(b.name, 'es'),
    );
    const others = showLoad
        ? []
        : (people ?? []).map((person) => ({
              id: person.id,
              name: person.name,
              department: null,
          }));
    const chosen = candidates?.find((person) => person.id === userId) ?? null;
    const after =
        chosen && chosen.has_schedule
            ? loadPercent(
                  chosen.load + allocation.planned_minutes,
                  chosen.capacity,
              )
            : null;

    const submit = () => {
        if (userId === null) {
            return;
        }

        setSaving(true);
        router.post(
            assign.url(allocation.id),
            { user_id: userId },
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
                onError: toastVisitErrors,
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="sm:max-w-md"
                data-test="assign-gap-dialog"
            >
                <DialogHeader>
                    <DialogTitle>
                        {t('forecast.assign.title', {
                            department: departmentName,
                        })}
                    </DialogTitle>
                    <DialogDescription>
                        {t('forecast.assign.description', {
                            amount: allocationAmountLabel(allocation),
                            dates: dateRange(
                                allocation.start_date,
                                allocation.end_date,
                            ),
                            hours: formatHours(allocation.planned_minutes),
                        })}
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-2">
                    <label htmlFor={id} className="text-sm">
                        {t('forecast.assign.person')}
                    </label>
                    {showLoad && candidates === null && !failed ? (
                        <p
                            className="flex items-center gap-2 text-sm text-muted-foreground"
                            role="status"
                        >
                            <Spinner aria-hidden="true" />
                            {t('forecast.assign.loading')}
                        </p>
                    ) : (
                        <PersonLoadPicker
                            id={id}
                            value={userId}
                            onChange={setUserId}
                            team={team}
                            others={others}
                        />
                    )}
                    {failed ? (
                        <p className="text-sm text-danger">
                            {t('forecast.assign.error')}
                        </p>
                    ) : null}
                    {after !== null && after > 100 ? (
                        <p
                            className="flex items-start gap-1.5 text-sm"
                            role="status"
                            data-test="assign-overload"
                        >
                            <TriangleAlert
                                aria-hidden="true"
                                className="mt-0.5 size-4 shrink-0 text-warning"
                            />
                            {t('forecast.assign.overload', {
                                name: chosen?.name ?? '',
                                percent: formatPercentValue(after),
                            })}
                        </p>
                    ) : null}
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={() => onOpenChange(false)}
                    >
                        {t('forecast.actions.cancel')}
                    </Button>
                    <Button
                        type="button"
                        onClick={submit}
                        disabled={userId === null || saving}
                    >
                        <UserPlus aria-hidden="true" />
                        {t('forecast.assign.submit')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
