import type { LucideIcon } from 'lucide-react';
import {
    AtSign,
    Bell,
    CircleCheck,
    Clock,
    ListChecks,
    MessageSquare,
    TriangleAlert,
    Undo2,
    UserPlus,
    Wallet,
} from 'lucide-react';

/**
 * Iconos permitidos para las notificaciones (App\Notifications\AppNotification::icon()).
 * Lista cerrada: el servidor manda un nombre de lucide y aquí se traduce; si no está, campana.
 */
const ICONS: Record<string, LucideIcon> = {
    bell: Bell,
    'at-sign': AtSign,
    'circle-check': CircleCheck,
    'check-circle': CircleCheck,
    clock: Clock,
    'list-checks': ListChecks,
    'message-square': MessageSquare,
    'triangle-alert': TriangleAlert,
    undo: Undo2,
    'undo-2': Undo2,
    'user-plus': UserPlus,
    wallet: Wallet,
};

export function notificationIcon(name: string | null): LucideIcon {
    return (name && ICONS[name]) || Bell;
}
