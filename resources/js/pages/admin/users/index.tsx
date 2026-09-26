import { Head, Link, usePage } from '@inertiajs/react';
import { SearchX, Users } from 'lucide-react';
import { useId } from 'react';
import { AdminPage } from '@/components/admin/admin-page';
import {
    AccountStatusBadge,
    RoleBadge,
    roleLabel,
} from '@/components/admin/badges';
import { ColorDot } from '@/components/admin/color-picker';
import { InviteUserDialog } from '@/components/admin/invite-user-dialog';
import { NativeSelect } from '@/components/admin/native-select';
import { Pagination } from '@/components/admin/pagination';
import { useListFilters } from '@/components/admin/use-list-filters';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as adminIndex } from '@/routes/admin';
import { edit, index as usersIndex } from '@/routes/admin/users';
import type { AdminUsersIndexProps } from '@/types';

/** Usuarios internos (SPEC §14): búsqueda, filtros, alta por invitación y acceso a cada ficha. */
export default function AdminUsersIndex({
    users,
    filters: initialFilters,
    departments,
    roles,
    canGrantAdmin,
}: AdminUsersIndexProps) {
    const id = useId();
    const showFinancials = usePage().props.auth?.can?.viewFinancials === true;
    const { filters, update, reset } = useListFilters(
        usersIndex.url(),
        {
            q: initialFilters.q,
            rol: initialFilters.rol,
            departamento: initialFilters.departamento,
            estado: initialFilters.estado,
        },
        { estado: 'activos' },
        ['users', 'filters'],
    );
    const filtered =
        filters.q !== '' ||
        filters.rol !== null ||
        filters.departamento !== null ||
        filters.estado !== 'activos';

    return (
        <>
            <Head title={t('admin.users.title')} />

            <AdminPage
                section="users"
                title={t('admin.users.heading')}
                description={t('admin.users.description')}
                actions={
                    <InviteUserDialog
                        roles={roles}
                        departments={departments}
                        canGrantAdmin={canGrantAdmin}
                        showFinancials={showFinancials}
                    />
                }
            >
                <div className="grid gap-4">
                    <form
                        role="search"
                        aria-label={t('admin.users.filters_label')}
                        className="grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_repeat(3,minmax(0,1fr))_auto] lg:items-end"
                        onSubmit={(event) => event.preventDefault()}
                    >
                        <div className="grid gap-2">
                            <Label htmlFor={`${id}-q`}>
                                {t('admin.users.search')}
                            </Label>
                            <Input
                                id={`${id}-q`}
                                type="search"
                                value={filters.q ?? ''}
                                placeholder={t(
                                    'admin.users.search_placeholder',
                                )}
                                onChange={(event) =>
                                    update('q', event.target.value, true)
                                }
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={`${id}-role`}>
                                {t('admin.users.fields.role')}
                            </Label>
                            <NativeSelect
                                id={`${id}-role`}
                                value={filters.rol ?? ''}
                                onChange={(event) =>
                                    update('rol', event.target.value || null)
                                }
                            >
                                <option value="">
                                    {t('admin.filters.all_roles')}
                                </option>
                                {roles.map((role) => (
                                    <option key={role} value={role}>
                                        {roleLabel(role)}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={`${id}-department`}>
                                {t('admin.users.fields.department')}
                            </Label>
                            <NativeSelect
                                id={`${id}-department`}
                                value={filters.departamento ?? ''}
                                onChange={(event) =>
                                    update(
                                        'departamento',
                                        event.target.value || null,
                                    )
                                }
                            >
                                <option value="">
                                    {t('admin.filters.all_departments')}
                                </option>
                                <option value="ninguno">
                                    {t('admin.users.fields.no_department')}
                                </option>
                                {departments.map((department) => (
                                    <option
                                        key={department.id}
                                        value={String(department.id)}
                                    >
                                        {department.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={`${id}-status`}>
                                {t('admin.filters.status')}
                            </Label>
                            <NativeSelect
                                id={`${id}-status`}
                                value={filters.estado ?? 'activos'}
                                onChange={(event) =>
                                    update('estado', event.target.value)
                                }
                            >
                                <option value="activos">
                                    {t('admin.filters.active')}
                                </option>
                                <option value="inactivos">
                                    {t('admin.filters.inactive')}
                                </option>
                                <option value="todos">
                                    {t('admin.filters.all')}
                                </option>
                            </NativeSelect>
                        </div>
                        {filtered ? (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={reset}
                            >
                                {t('admin.filters.clear')}
                            </Button>
                        ) : null}
                    </form>

                    {users.data.length === 0 ? (
                        <EmptyState
                            icon={filtered ? SearchX : Users}
                            title={
                                filtered
                                    ? t('admin.users.empty_filtered')
                                    : t('admin.users.empty')
                            }
                            description={
                                filtered
                                    ? t(
                                          'admin.users.empty_filtered_description',
                                      )
                                    : t('admin.users.empty_description')
                            }
                        />
                    ) : (
                        <div
                            className={cn(
                                'overflow-x-auto rounded-md border',
                                FOCUS_RING,
                            )}
                            role="region"
                            aria-label={t('admin.users.table_label')}
                            tabIndex={0}
                        >
                            <table className="w-full min-w-[44rem] text-sm">
                                <caption className="sr-only">
                                    {t('admin.users.table_label')}
                                </caption>
                                <thead>
                                    <tr className="border-b text-left">
                                        <th
                                            scope="col"
                                            className="px-3 py-2 font-medium"
                                        >
                                            {t('admin.users.columns.person')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 font-medium"
                                        >
                                            {t('admin.users.fields.role')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 font-medium"
                                        >
                                            {t('admin.users.fields.department')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 font-medium"
                                        >
                                            {t('admin.filters.status')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 font-medium"
                                        >
                                            {t(
                                                'admin.users.columns.last_login',
                                            )}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {users.data.map((user) => (
                                        <tr
                                            key={user.id}
                                            className="border-b last:border-b-0 even:bg-muted"
                                            data-test="user-row"
                                        >
                                            <td className="px-3 py-2">
                                                <Link
                                                    href={edit.url(user.id)}
                                                    className={cn(
                                                        'rounded-sm font-medium text-foreground hover:underline',
                                                        FOCUS_RING,
                                                    )}
                                                >
                                                    {user.name}
                                                </Link>
                                                <span className="block text-xs break-all text-muted-foreground">
                                                    {user.email}
                                                </span>
                                            </td>
                                            <td className="px-3 py-2">
                                                <RoleBadge role={user.role} />
                                            </td>
                                            <td className="px-3 py-2">
                                                {user.department ? (
                                                    <span className="inline-flex items-center gap-2">
                                                        <ColorDot
                                                            color={
                                                                user.department
                                                                    .color
                                                            }
                                                        />
                                                        {user.department.name}
                                                    </span>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        {t(
                                                            'admin.users.fields.no_department',
                                                        )}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-3 py-2">
                                                <AccountStatusBadge
                                                    isActive={user.is_active}
                                                    pending={
                                                        user.last_login_at ===
                                                        null
                                                    }
                                                />
                                            </td>
                                            <td className="tabular px-3 py-2 whitespace-nowrap text-muted-foreground">
                                                {user.last_login_at
                                                    ? formatDateTime(
                                                          user.last_login_at,
                                                      )
                                                    : t('admin.users.never')}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    <Pagination
                        page={users}
                        label={t('admin.users.pagination_label')}
                    />
                </div>
            </AdminPage>
        </>
    );
}

AdminUsersIndex.layout = {
    breadcrumbs: [
        { title: t('nav.admin'), href: adminIndex() },
        { title: t('admin.users.title'), href: usersIndex() },
    ],
};
