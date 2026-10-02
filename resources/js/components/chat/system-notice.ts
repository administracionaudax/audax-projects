import type { LucideIcon } from 'lucide-react';
import { Flag, Gauge, Info, TriangleAlert } from 'lucide-react';
import { t } from '@/lib/i18n';
import type { ChatSystemData } from '@/types/chat';

/**
 * Mensajes de sistema del chat del proyecto (App\Domain\Chat\Notices\ProjectChatNotices): el
 * servidor manda la clave y los datos y aquí se escriben el texto y el icono (siempre los dos).
 */

function text(value: unknown, fallback = ''): string {
    return typeof value === 'string' || typeof value === 'number'
        ? String(value)
        : fallback;
}

export function systemText(system: ChatSystemData): string {
    const payload = system.payload;

    switch (system.key) {
        case 'hour_bank.threshold': {
            const threshold = Number(payload.threshold ?? 0);
            const bank = text(payload.bank, t('chat.system.a_bank'));

            return threshold >= 100
                ? t('chat.system.bank_exhausted', { bank })
                : t('chat.system.bank_threshold', { bank, threshold });
        }
        case 'milestone.completed':
            return t('chat.system.milestone_completed', {
                task: text(payload.task, t('chat.system.a_milestone')),
            });
        default:
            return t('chat.system.generic');
    }
}

export function systemIcon(system: ChatSystemData): LucideIcon {
    switch (system.key) {
        case 'hour_bank.threshold':
            return Number(system.payload.threshold ?? 0) >= 100
                ? TriangleAlert
                : Gauge;
        case 'milestone.completed':
            return Flag;
        default:
            return Info;
    }
}
