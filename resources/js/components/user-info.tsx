import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { awayLabel } from '@/components/weeklies/away-dialog';
import { useInitials } from '@/hooks/use-initials';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Role, User } from '@/types';

/** Orden para elegir el rol que se enseña si hay varios. */
const ROLE_ORDER: Role[] = [
    'admin',
    'department_manager',
    'employee',
    'collaborator',
    'client',
];

/** El rol principal de una persona (F-008): «Administración», «Empleado»… */
export function mainRole(roles: Role[]): Role | null {
    return ROLE_ORDER.find((role) => roles.includes(role)) ?? null;
}

export function UserInfo({
    user,
    showEmail = false,
    showRole = false,
}: {
    user: User;
    showEmail?: boolean;
    /** El rol bajo el nombre (pie de la barra lateral, F-008). */
    showRole?: boolean;
}) {
    const getInitials = useInitials();
    const away = user.weekly_away ?? null;
    const role = showRole ? mainRole(user.roles ?? []) : null;

    return (
        <>
            <span className="relative shrink-0">
                <Avatar className="h-8 w-8 overflow-hidden rounded-full">
                    <AvatarImage src={user.avatar ?? undefined} alt="" />
                    <AvatarFallback className="rounded-full bg-neutral-soft text-xs font-medium text-foreground">
                        {getInitials(user.name)}
                    </AvatarFallback>
                </Avatar>
                {away ? (
                    // Insignia de «Estoy fuera» (D-228), como la de WeeklySync sobre el avatar.
                    <span
                        className={cn(
                            'absolute -right-0.5 -bottom-0.5 size-3 rounded-full border-2 border-sidebar',
                            away.reason === 'vacation'
                                ? 'bg-warning'
                                : 'bg-danger',
                        )}
                        data-test="user-away-badge"
                    >
                        <span className="sr-only">
                            {t('weeklies.away.badge', {
                                status: awayLabel(away),
                            })}
                        </span>
                    </span>
                ) : null}
            </span>
            <div className="grid flex-1 text-left text-sm leading-tight">
                <span className="truncate font-medium">{user.name}</span>
                {showEmail && (
                    <span className="truncate text-xs text-muted-foreground">
                        {user.email}
                    </span>
                )}
                {role ? (
                    <span
                        className="truncate text-xs text-muted-foreground"
                        data-test="user-role"
                    >
                        {t(`admin.roles.${role}`)}
                    </span>
                ) : null}
            </div>
        </>
    );
}
