import { Link } from '@inertiajs/react';
import { CalendarClock } from 'lucide-react';
import { useEffect, useState } from 'react';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as teamIndex, pending } from '@/routes/absences/team';

/**
 * Aviso en /horas/aprobaciones (D-049): cuántas solicitudes de ausencia esperan a quien revisa,
 * con el enlace a «Ausencias del equipo». Pide el número (GET /ausencias/equipo/pendientes) al
 * montarse; sin solicitudes, o si la petición falla, no se muestra nada (es un complemento de
 * la página de horas y no debe estorbarla).
 */
export function PendingAbsencesNotice() {
    const [count, setCount] = useState(0);

    useEffect(() => {
        const controller = new AbortController();

        fetch(pending.url(), {
            method: 'GET',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then((response) =>
                response.ok
                    ? (response.json() as Promise<{ count?: unknown }>)
                    : { count: 0 },
            )
            .then((data) =>
                setCount(typeof data.count === 'number' ? data.count : 0),
            )
            .catch(() => {
                // Sin aviso: la página de horas sigue funcionando igual.
            });

        return () => controller.abort();
    }, []);

    if (count === 0) {
        return null;
    }

    return (
        <div
            role="status"
            className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md border border-info bg-info-soft p-3 text-sm"
            data-test="pending-absences-notice"
        >
            <CalendarClock
                aria-hidden="true"
                className="size-4 shrink-0 text-info"
            />
            <span>
                {count === 1
                    ? t('absences.approvals_notice.one')
                    : t('absences.approvals_notice.many', { count })}
            </span>
            <Link
                href={teamIndex.url()}
                className={cn(
                    'rounded-sm text-primary-text hover:underline',
                    FOCUS_RING,
                )}
            >
                {t('absences.approvals_notice.link')}
            </Link>
        </div>
    );
}
