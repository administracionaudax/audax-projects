import { router } from '@inertiajs/react';
import {
    CalendarPlus,
    CircleCheck,
    CircleDashed,
    MapPin,
    Scale,
} from 'lucide-react';
import { useId, useState } from 'react';
import { toastVisitErrors } from '@/components/admin/visit-errors';
import { StatusBadge } from '@/components/styleguide/status-badges';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { valencia as addValencia } from '@/routes/admin/holidays';
import type { HolidayLevel } from '@/types/leave';

export type ValenciaHoliday = {
    date: string;
    name: string;
    level: HolidayLevel;
    source: string;
    pending: boolean;
    exists: boolean;
};

/**
 * Calendario laboral de València (Fase 11, R3; L-23; D-367): las fiestas del año comprobadas en el
 * BOE, el DOGV y valencia.es, con su nivel y su fuente, y los días del convenio de publicidad
 * (pendientes de asesor), que solo se añaden si se marcan.
 */
export function ValenciaHolidaysPanel({
    year,
    holidays,
    agreement,
}: {
    year: number;
    holidays: ValenciaHoliday[];
    agreement: ValenciaHoliday[];
}) {
    const id = useId();
    const [withAgreement, setWithAgreement] = useState(false);
    const [adding, setAdding] = useState(false);
    const missing =
        holidays.filter((holiday) => !holiday.exists).length +
        (withAgreement
            ? agreement.filter((holiday) => !holiday.exists).length
            : 0);

    const row = (holiday: ValenciaHoliday) => (
        <li
            key={holiday.date}
            className="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-x-3"
        >
            <span>
                <span className="tabular">{formatDate(holiday.date)}</span>{' '}
                {holiday.name}{' '}
                <span className="text-muted-foreground">
                    · {t(`leave.levels.${holiday.level}` as TranslationKey)}
                    {holiday.pending && holiday.level !== 'company'
                        ? ` · ${t('leave.valencia.pending_dogv')}`
                        : ''}
                </span>
                <span className="block text-xs text-muted-foreground">
                    {holiday.source}
                </span>
            </span>
            {holiday.exists ? (
                <StatusBadge tone="success" icon={CircleCheck}>
                    {t('holidays.national.present')}
                </StatusBadge>
            ) : (
                <StatusBadge tone="neutral" icon={CircleDashed}>
                    {t('holidays.national.missing')}
                </StatusBadge>
            )}
        </li>
    );

    return (
        <section
            aria-labelledby={`${id}-heading`}
            className="grid min-w-0 content-start gap-3 rounded-md border p-4"
            data-test="valencia-holidays"
        >
            <div className="space-y-1">
                <h2
                    id={`${id}-heading`}
                    className="flex items-center gap-2 text-lg"
                >
                    <MapPin
                        aria-hidden="true"
                        className="size-5 text-muted-foreground"
                        strokeWidth={1.5}
                    />
                    {t('leave.valencia.heading', { year })}
                </h2>
                <p className="text-sm text-muted-foreground">
                    {t('leave.valencia.description')}
                </p>
            </div>
            <ul className="grid gap-1.5 text-sm">{holidays.map(row)}</ul>
            <div className="grid gap-1.5 rounded-md bg-warning-soft p-3 text-sm">
                <p className="flex items-center gap-1.5">
                    <Scale
                        aria-hidden="true"
                        className="size-4 shrink-0 text-warning"
                    />
                    {t('leave.valencia.agreement')}
                </p>
                <ul className="grid gap-1.5">{agreement.map(row)}</ul>
                <div className="flex items-center gap-2">
                    <Checkbox
                        id={`${id}-agreement`}
                        checked={withAgreement}
                        onCheckedChange={(checked) =>
                            setWithAgreement(checked === true)
                        }
                    />
                    <Label htmlFor={`${id}-agreement`} className="font-normal">
                        {t('leave.valencia.include_agreement')}
                    </Label>
                </div>
            </div>
            <Button
                type="button"
                variant={missing > 0 ? 'default' : 'outline'}
                className="justify-self-start"
                disabled={adding || missing === 0}
                onClick={() =>
                    router.post(
                        addValencia.url(),
                        { year, agreement: withAgreement },
                        {
                            preserveScroll: true,
                            onError: toastVisitErrors,
                            onStart: () => setAdding(true),
                            onFinish: () => setAdding(false),
                        },
                    )
                }
            >
                {adding ? <Spinner /> : <CalendarPlus aria-hidden="true" />}
                {missing === 0
                    ? t('leave.valencia.complete')
                    : t('leave.valencia.add', { count: missing })}
            </Button>
        </section>
    );
}
