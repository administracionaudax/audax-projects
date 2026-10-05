import { CalendarOff, Copy } from 'lucide-react';
import { toast } from 'sonner';
import { StatusBadge } from '@/components/styleguide/status-badges';
import { useClipboard } from '@/hooks/use-clipboard';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PersonAbsenceToday } from '@/types/weekly-insights';

/**
 * «Ausente» de hoy (F-136, las insignias VACACIONES y AUSENTE de WeeklySync): el tipo solo si quien
 * mira puede saberlo (D-088: una baja es un dato de salud); si no, «Ausente».
 */
export function AbsenceTodayBadge({
    absence,
}: {
    absence: PersonAbsenceToday | null;
}) {
    if (absence === null) {
        return null;
    }

    const until = formatDate(absence.until);

    return (
        <StatusBadge tone="warning" icon={CalendarOff}>
            {absence.type
                ? t('weeklies.team.absence_type_until', {
                      type: t(`absences.type.${absence.type}`),
                      date: until,
                  })
                : t('weeklies.team.absent_until', { date: until })}
        </StatusBadge>
    );
}

/** Botón de copiar el email (F-137), con aviso. */
export function CopyEmailButton({
    email,
    name,
}: {
    email: string;
    name: string;
}) {
    const [, copy] = useClipboard();
    const label = t('weeklies.team.copy_email', { name });

    return (
        <button
            type="button"
            onClick={async () => {
                if (await copy(email)) {
                    toast.success(t('weeklies.team.copied'));
                } else {
                    toast.error(t('weeklies.team.copy_failed'));
                }
            }}
            aria-label={label}
            title={label}
            className={cn(
                'inline-flex size-6 shrink-0 items-center justify-center text-muted-foreground hover:text-foreground',
                FOCUS_RING,
            )}
            data-test="copy-email"
        >
            <Copy aria-hidden="true" className="size-3.5" />
        </button>
    );
}
