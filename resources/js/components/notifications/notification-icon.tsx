import type { LucideIcon } from 'lucide-react';
import {
    AtSign,
    Bell,
    CalendarClock,
    CircleCheck,
    CircleDot,
    Clock,
    Gauge,
    ListChecks,
    MessageSquare,
    Timer,
    TriangleAlert,
    Undo2,
    UserCheck,
    UserPlus,
    Wallet,
} from 'lucide-react';

/**
 * Iconos permitidos para las notificaciones (App\Notifications\AppNotification::icon()).
 * Lista cerrada: el servidor manda un nombre de lucide y aquí se traduce; si no está, campana.
 * Cada icon() del servidor debe estar aquí (tests/Feature/NotificationIconsTest.php).
 */
const ICONS: Record<string, LucideIcon> = {
    bell: Bell,
    'at-sign': AtSign,
    'calendar-clock': CalendarClock,
    'circle-check': CircleCheck,
    'check-circle': CircleCheck,
    'circle-dot': CircleDot,
    clock: Clock,
    gauge: Gauge,
    'list-checks': ListChecks,
    'message-square': MessageSquare,
    timer: Timer,
    'triangle-alert': TriangleAlert,
    undo: Undo2,
    'undo-2': Undo2,
    'user-check': UserCheck,
    'user-plus': UserPlus,
    wallet: Wallet,
};

/** Nombres que se traducen a un icono propio (el resto, campana). */
export const NOTIFICATION_ICON_NAMES: readonly string[] = Object.keys(ICONS);

export function notificationIcon(name: string | null): LucideIcon {
    return (name && ICONS[name]) || Bell;
}
