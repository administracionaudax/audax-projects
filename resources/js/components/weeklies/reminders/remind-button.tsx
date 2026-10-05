import { router } from '@inertiajs/react';
import { BellRing } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { remind } from '@/routes/weeklies/reminders';

/**
 * «Recordar» a una persona pendiente de la semana activa (F-037 y F-110, 10.5): manda el
 * recordatorio manual por los canales que esa persona tenga activados. El resultado (enviado, no lo
 * necesita o ya se le mandó hace un momento) llega como aviso del servidor.
 */
export function RemindButton({
    cycleId,
    person,
}: {
    cycleId: number;
    person: { id: number; name: string };
}) {
    const [sending, setSending] = useState(false);

    return (
        <Button
            type="button"
            variant="ghost"
            size="sm"
            disabled={sending}
            aria-label={t('weekly_reminders.remind_person', {
                name: person.name,
            })}
            data-test="weekly-remind"
            onClick={() =>
                router.post(
                    remind.url(cycleId),
                    { user_id: person.id },
                    {
                        preserveScroll: true,
                        onStart: () => setSending(true),
                        onFinish: () => setSending(false),
                    },
                )
            }
        >
            {sending ? <Spinner /> : <BellRing aria-hidden="true" />}
            {t('weekly_reminders.remind')}
        </Button>
    );
}
