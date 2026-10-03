import type { LucideIcon } from 'lucide-react';
import {
    CircleCheck,
    CircleSlash,
    MailQuestion,
    ShieldCheck,
    User,
    UserCog,
    UserRoundPlus,
} from 'lucide-react';
import { StatusBadge } from '@/components/styleguide/status-badges';
import { t } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type { Role } from '@/types';

const ROLES: Record<Role, { label: TranslationKey; icon: LucideIcon }> = {
    admin: { label: 'admin.roles.admin', icon: ShieldCheck },
    department_manager: {
        label: 'admin.roles.department_manager',
        icon: UserCog,
    },
    employee: { label: 'admin.roles.employee', icon: User },
    collaborator: { label: 'admin.roles.collaborator', icon: UserRoundPlus },
    client: { label: 'admin.roles.client', icon: User },
};

export function roleLabel(role: Role | null): string {
    return role ? t(ROLES[role].label) : t('admin.roles.none');
}

/** Rol de una persona: icono + texto (nunca solo color). */
export function RoleBadge({ role }: { role: Role | null }) {
    const meta = role ? ROLES[role] : null;

    return (
        <StatusBadge tone="neutral" icon={meta?.icon ?? User}>
            {roleLabel(role)}
        </StatusBadge>
    );
}

/** Estado de una cuenta: activa, desactivada o invitación pendiente (nunca ha entrado). */
export function AccountStatusBadge({
    isActive,
    pending = false,
}: {
    isActive: boolean;
    pending?: boolean;
}) {
    if (!isActive) {
        return (
            <StatusBadge tone="neutral" icon={CircleSlash}>
                {t('admin.users.status.inactive')}
            </StatusBadge>
        );
    }

    if (pending) {
        return (
            <StatusBadge tone="info" icon={MailQuestion}>
                {t('admin.users.status.pending')}
            </StatusBadge>
        );
    }

    return (
        <StatusBadge tone="success" icon={CircleCheck}>
            {t('admin.users.status.active')}
        </StatusBadge>
    );
}
