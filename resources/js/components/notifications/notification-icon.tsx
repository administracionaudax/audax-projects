import type { LucideIcon } from 'lucide-react';
import {
    AtSign,
    Bell,
    CalendarCheck,
    CalendarClock,
    CalendarOff,
    CalendarPlus,
    CalendarX,
    CircleCheck,
    CircleDot,
    Clock,
    FileCheck,
    Gauge,
    Hourglass,
    ListChecks,
    MessageSquare,
    NotebookPen,
    Paperclip,
    Timer,
    TriangleAlert,
    Rocket,
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
    'calendar-check': CalendarCheck,
    'calendar-clock': CalendarClock,
    'calendar-off': CalendarOff,
    'calendar-plus': CalendarPlus,
    'calendar-x': CalendarX,
    'circle-check': CircleCheck,
    'check-circle': CircleCheck,
    'circle-dot': CircleDot,
    clock: Clock,
    'file-check': FileCheck,
    gauge: Gauge,
    hourglass: Hourglass,
    'list-checks': ListChecks,
    'message-square': MessageSquare,
    'notebook-pen': NotebookPen,
    paperclip: Paperclip,
    rocket: Rocket,
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
