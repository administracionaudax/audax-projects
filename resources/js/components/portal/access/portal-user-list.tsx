import { router } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    Ban,
    CircleCheck,
    Clock,
    Ellipsis,
    Power,
    Send,
    UserX,
} from 'lucide-react';
import { useRef, useState } from 'react';
import { toastVisitErrors } from '@/components/admin/visit-errors';
import { ConfirmDialog } from '@/components/confirm-dialog';
import type { PortalUserRow } from '@/components/portal/access/types';
import { StatusBadge } from '@/components/styleguide/status-badges';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { formatDate, formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { invitation, reactivate, revoke } from '@/routes/clients/portal/users';

type Tone = 'neutral' | 'info' | 'success' | 'warning';

/** Estado de acceso de un usuario del portal: siempre con icono y texto (nunca solo color). */
export function portalUserStatus(user: PortalUserRow): {
    tone: Tone;
    icon: LucideIcon;
    label: string;
} {
    if (!user.is_active) {
        return {
            tone: 'neutral',
            icon: Ban,
            label: t('portal_access.status.revoked'),
        };
    }

    if (user.invitation === 'accepted') {
        return {
            tone: 'success',
            icon: CircleCheck,
            label: t('portal_access.status.active'),
        };
    }

    return user.invitation === 'pending'
        ? { tone: 'info', icon: Send, label: t('portal_access.status.pending') }
        : {
              tone: 'warning',
              icon: Clock,
              label: t('portal_access.status.expired'),
          };
}

function detail(user: PortalUserRow): string {
    if (user.last_login_at) {
        return t('portal_access.users.last_login', {
            date: formatDateTime(user.last_login_at),
        });
    }

    if (
        user.is_active &&
        user.invitation === 'pending' &&
        user.invitation_expires_at
    ) {
        return t('portal_access.users.expires', {
            date: formatDate(user.invitation_expires_at),
        });
    }

    return t('portal_access.users.never');
}

type Action = 'invitation' | 'revoke' | 'reactivate';

/**
 * Usuarios del portal de un cliente (D-063): nombre, correo, estado de acceso e invitación y último
 * acceso. Con permiso, cada uno tiene sus acciones: reenviar la invitación, revocar el acceso (con
 * confirmación: cierra sus sesiones al momento) o reactivarlo.
 */
export function PortalUserList({
    clientId,
    users,
    canManage,
    clientActive,
}: {
    clientId: number;
    users: ReadonlyArray<PortalUserRow>;
    canManage: boolean;
    clientActive: boolean;
}) {
    const [revoking, setRevoking] = useState<PortalUserRow | null>(null);
    const [processing, setProcessing] = useState(false);
    // Al cerrar la confirmación, el foco vuelve al botón de acciones de esa persona.
    const buttons = useRef(new Map<number, HTMLButtonElement>());
    const focusAfter = useRef<number | null>(null);

    const run = (action: Action, user: PortalUserRow) => {
        const target = { client: clientId, portalUser: user.id };
        const url =
            action === 'invitation'
                ? invitation.url(target)
                : action === 'revoke'
                  ? revoke.url(target)
                  : reactivate.url(target);

        router.post(
            url,
            {},
            {
                preserveScroll: true,
                onError: toastVisitErrors,
                onStart: () => setProcessing(true),
                onFinish: () => {
                    setProcessing(false);
                    setRevoking(null);
                },
            },
        );
    };

    return (
        <>
            <ul
                className="grid divide-y rounded-md border"
                aria-label={t('portal_access.users.label')}
                data-test="portal-users"
            >
                {users.map((user) => {
                    const status = portalUserStatus(user);

                    return (
                        <li
                            key={user.id}
                            className="flex items-start justify-between gap-3 p-3"
                            data-test="portal-user"
                        >
                            <div className="grid min-w-0 gap-1">
                                <p className="truncate text-sm font-medium">
                                    {user.name}
                                </p>
                                <p className="truncate text-sm text-muted-foreground">
                                    {user.email}
                                </p>
                                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <StatusBadge
                                        tone={status.tone}
                                        icon={status.icon}
                                    >
                                        {status.label}
                                    </StatusBadge>
                                    <span className="text-xs text-muted-foreground">
                                        {detail(user)}
                                    </span>
                                </div>
                            </div>

                            {canManage ? (
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="shrink-0"
                                            disabled={processing}
                                            aria-label={t(
                                                'portal_access.users.actions',
                                                {
                                                    name: user.name,
                                                },
                                            )}
                                            data-test="portal-user-actions"
                                            ref={(element) => {
                                                if (element) {
                                                    buttons.current.set(
                                                        user.id,
                                                        element,
                                                    );
                                                } else {
                                                    buttons.current.delete(
                                                        user.id,
                                                    );
                                                }
                                            }}
                                        >
                                            <Ellipsis aria-hidden="true" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                        align="end"
                                        className="w-56"
                                    >
                                        {user.is_active ? (
                                            <>
                                                {user.invitation !==
                                                'accepted' ? (
                                                    <DropdownMenuItem
                                                        disabled={!clientActive}
                                                        onSelect={() =>
                                                            run(
                                                                'invitation',
                                                                user,
                                                            )
                                                        }
                                                    >
                                                        <Send aria-hidden="true" />
                                                        {t(
                                                            'portal_access.users.resend',
                                                        )}
                                                    </DropdownMenuItem>
                                                ) : null}
                                                <DropdownMenuItem
                                                    variant="destructive"
                                                    onSelect={() => {
                                                        focusAfter.current =
                                                            user.id;
                                                        setRevoking(user);
                                                    }}
                                                >
                                                    <UserX aria-hidden="true" />
                                                    {t(
                                                        'portal_access.users.revoke',
                                                    )}
                                                </DropdownMenuItem>
                                            </>
                                        ) : (
                                            <DropdownMenuItem
                                                disabled={!clientActive}
                                                onSelect={() =>
                                                    run('reactivate', user)
                                                }
                                            >
                                                <Power aria-hidden="true" />
                                                {t(
                                                    'portal_access.users.reactivate',
                                                )}
                                            </DropdownMenuItem>
                                        )}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            ) : null}
                        </li>
                    );
                })}
            </ul>

            <ConfirmDialog
                open={revoking !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setRevoking(null);
                    }
                }}
                trigger={<span hidden />}
                onCloseAutoFocus={(event) => {
                    const button =
                        focusAfter.current !== null
                            ? buttons.current.get(focusAfter.current)
                            : undefined;
                    focusAfter.current = null;

                    if (button) {
                        event.preventDefault();
                        button.focus();
                    }
                }}
                title={t('portal_access.revoke.title', {
                    name: revoking?.name ?? '',
                })}
                description={t('portal_access.revoke.description')}
                confirmLabel={t('portal_access.users.revoke')}
                processing={processing}
                onConfirm={() => {
                    if (revoking) {
                        run('revoke', revoking);
                    }
                }}
            />
        </>
    );
}
