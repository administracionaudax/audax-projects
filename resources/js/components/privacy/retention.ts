import { t } from '@/lib/i18n';
import type { RetentionType } from '@/types/privacy';

/** 12 → «12 meses»; 24 → «2 años»; null → «Sin límite». */
export function formatRetention(months: number | null): string {
    if (months === null) {
        return t('privacy.retention.unlimited');
    }

    if (months % 12 === 0) {
        const years = months / 12;

        return years === 1
            ? t('privacy.retention.one_year')
            : t('privacy.retention.years', { count: years });
    }

    return months === 1
        ? t('privacy.retention.one_month')
        : t('privacy.retention.months', { count: months });
}

/** Nombre de cada tipo de dato con plazo de conservación (RetentionPolicy). */
export function retentionLabel(type: RetentionType): string {
    switch (type) {
        case 'login_events':
            return t('privacy.retention.types.login_events');
        case 'read_notifications':
            return t('privacy.retention.types.read_notifications');
        case 'activity_log':
            return t('privacy.retention.types.activity_log');
        case 'chat_messages':
            return t('privacy.retention.types.chat_messages');
        case 'weekly_reminder_logs':
            return t('privacy.retention.types.weekly_reminder_logs');
        case 'dictations':
            return t('privacy.retention.types.dictations');
        case 'ai_usage':
            return t('privacy.retention.types.ai_usage');
        case 'day_plans':
            return t('privacy.retention.types.day_plans');
    }
}
