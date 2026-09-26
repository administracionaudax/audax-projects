/**
 * Props de las páginas del área «notifications» (Fase 1). Los tipos de entidad están en ./domain.
 */
import type { AppNotification } from './domain';

export type Paginated<T> = {
    data: T[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
};

export type NotificationsPageProps = {
    /** No se llama `notifications` para no pisar la prop compartida (recuento de la campana). */
    items: Paginated<AppNotification>;
    filter: 'all' | 'unread';
    unread: number;
};

/** GET /notificaciones/recientes (campana). */
export type RecentNotificationsResponse = {
    unread: number;
    notifications: AppNotification[];
};
