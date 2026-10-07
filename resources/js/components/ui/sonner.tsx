import { useClockSummary } from '@/components/people/use-clock-summary';
import { useDayPlanPrompt } from '@/hooks/use-day-plan-prompt';
import { useFlashToast } from '@/hooks/use-flash-toast';
import { useAppearance } from '@/hooks/use-appearance';
import { Toaster as Sonner, type ToasterProps } from 'sonner';
import { t } from '@/lib/i18n';

function Toaster({ ...props }: ToasterProps) {
    const { appearance } = useAppearance();

    useFlashToast();
    // Plan del día (D-254): «¿Das por hecha la línea?» al parar su temporizador.
    useDayPlanPrompt();
    // Registro de jornada (D-340): «Hoy has trabajado… e imputado…» al fichar la salida.
    useClockSummary();

    return (
        <Sonner
            theme={appearance}
            className="toaster group"
            position="bottom-right"
            // Sin etiqueta, sonner anuncia la región en inglés («Notifications»).
            containerAriaLabel={t('toast.region')}
            style={
                {
                    '--normal-bg': 'var(--popover)',
                    '--normal-text': 'var(--popover-foreground)',
                    '--normal-border': 'var(--border)',
                } as React.CSSProperties
            }
            {...props}
        />
    );
}

export { Toaster };
