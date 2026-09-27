import { RefreshCw, WifiOff } from 'lucide-react';
import { useRealtimeStatus } from '@/hooks/use-realtime-connection';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * Aviso discreto para la pantalla del chat cuando hay tiempo real configurado pero la conexión
 * se ha cortado o aún no se ha establecido. Sin tiempo real (o en vivo) no pinta nada.
 */
export function RealtimeConnectionNotice({
    className,
}: {
    className?: string;
}) {
    const status = useRealtimeStatus();

    if (status !== 'connecting' && status !== 'disconnected') {
        return null;
    }

    const Icon = status === 'connecting' ? RefreshCw : WifiOff;

    return (
        <p
            role="status"
            className={cn(
                'flex items-center gap-1.5 text-xs text-muted-foreground',
                className,
            )}
        >
            <Icon
                aria-hidden="true"
                className={cn(
                    'size-3.5 shrink-0',
                    status === 'connecting' && 'motion-safe:animate-spin',
                )}
            />
            {status === 'connecting'
                ? t('realtime.connection.connecting')
                : t('realtime.connection.disconnected')}
        </p>
    );
}
