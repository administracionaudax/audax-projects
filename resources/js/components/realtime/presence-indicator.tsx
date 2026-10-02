import { Circle, Moon } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { usePresence } from '@/hooks/use-presence';
import type { PresenceStatus } from '@/hooks/use-presence';
import { useInitials } from '@/hooks/use-initials';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * Presencia (SPEC §12): en línea, ausente o desconectado. Nunca solo con color (SPEC §3.1): cada
 * estado tiene su forma (círculo lleno, luna, círculo vacío) y su texto (visible o accesible).
 */

const ICON: Record<PresenceStatus, LucideIcon> = {
    online: Circle,
    away: Moon,
    offline: Circle,
};

const TONE: Record<PresenceStatus, string> = {
    online: 'fill-success text-success',
    away: 'fill-warning text-warning',
    offline: 'fill-transparent text-muted-foreground',
};

export function presenceLabel(status: PresenceStatus): string {
    return t(`realtime.presence.${status}`);
}

/** Icono del estado (con su texto accesible si no hay otro texto al lado). */
export function PresenceIcon({
    status,
    className,
    decorative = false,
}: {
    status: PresenceStatus;
    className?: string;
    decorative?: boolean;
}) {
    const Icon = ICON[status];
    const label = presenceLabel(status);

    return (
        <Icon
            aria-hidden={decorative ? true : undefined}
            aria-label={decorative ? undefined : label}
            role={decorative ? undefined : 'img'}
            className={cn('size-2.5 shrink-0', TONE[status], className)}
            data-status={status}
            strokeWidth={status === 'offline' ? 2.5 : 2}
        />
    );
}

/**
 * Punto de estado para la esquina de un avatar. El contenedor del avatar debe ser `relative`
 * (UserAvatar ya lo hace).
 */
export function PresenceDot({
    userId,
    className,
}: {
    userId: number;
    className?: string;
}) {
    const status = usePresence().statusOf(userId);
    const label = presenceLabel(status);

    return (
        <span
            role="img"
            aria-label={label}
            title={label}
            data-status={status}
            className={cn(
                'absolute -right-0.5 -bottom-0.5 flex size-3.5 items-center justify-center rounded-full bg-background',
                className,
            )}
        >
            <PresenceIcon status={status} decorative />
        </span>
    );
}

/** Estado con icono y texto, para listas y cabeceras («En línea», «Ausente», «Desconectado»). */
export function PresenceLabel({
    userId,
    className,
}: {
    userId: number;
    className?: string;
}) {
    const status = usePresence().statusOf(userId);

    return (
        <span
            data-status={status}
            className={cn(
                'inline-flex items-center gap-1.5 text-xs text-muted-foreground',
                className,
            )}
        >
            <PresenceIcon status={status} decorative />
            {presenceLabel(status)}
        </span>
    );
}

export type AvatarUser = {
    id: number;
    name: string;
    avatar?: string | null;
};

/** Avatar con iniciales de respaldo y, si se pide, el punto de presencia. */
export function UserAvatar({
    user,
    showPresence = false,
    className,
}: {
    user: AvatarUser;
    showPresence?: boolean;
    className?: string;
}) {
    const getInitials = useInitials();

    return (
        <span className={cn('relative inline-flex shrink-0', className)}>
            <Avatar className="size-8">
                <AvatarImage src={user.avatar ?? undefined} alt="" />
                <AvatarFallback className="bg-neutral-soft text-xs font-medium text-foreground">
                    {getInitials(user.name)}
                </AvatarFallback>
            </Avatar>
            {showPresence ? <PresenceDot userId={user.id} /> : null}
        </span>
    );
}
