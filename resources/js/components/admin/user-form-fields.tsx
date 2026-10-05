import { useId } from 'react';
import { roleLabel } from '@/components/admin/badges';
import { describedBy, Field } from '@/components/admin/field';
import { NativeSelect } from '@/components/admin/native-select';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';
import type { AdminAssignableRole, Department } from '@/types';

export type UserFormData = {
    name: string;
    email: string;
    role: AdminAssignableRole;
    department_id: string;
    job_title: string;
    hourly_cost: string;
    default_hourly_rate: string;
};

/**
 * Campos de una persona (invitar y editar): nombre, correo, rol, departamento, puesto (F-026) y, solo con
 * view-financials, coste/hora y tarifa por defecto. El rol de administración solo lo ofrece
 * quien es admin (el servidor lo comprueba igualmente).
 */
export function UserFormFields({
    data,
    setData,
    errors,
    roles,
    departments,
    canGrantAdmin,
    canChangeRole = true,
    showFinancials,
}: {
    data: UserFormData;
    setData: (key: keyof UserFormData, value: string) => void;
    errors: Partial<Record<keyof UserFormData, string>>;
    roles: AdminAssignableRole[];
    departments: Department[];
    canGrantAdmin: boolean;
    canChangeRole?: boolean;
    showFinancials: boolean;
}) {
    const id = useId();
    const ids = {
        name: `${id}-name`,
        email: `${id}-email`,
        role: `${id}-role`,
        department: `${id}-department`,
        jobTitle: `${id}-job-title`,
        cost: `${id}-cost`,
        rate: `${id}-rate`,
    };
    const roleOptions = roles.filter(
        (role) => role !== 'admin' || canGrantAdmin || data.role === 'admin',
    );

    return (
        <div className="grid gap-5 sm:grid-cols-2">
            <Field
                id={ids.name}
                label={t('admin.users.fields.name')}
                error={errors.name}
            >
                <Input
                    id={ids.name}
                    value={data.name}
                    onChange={(event) => setData('name', event.target.value)}
                    required
                    autoComplete="off"
                    maxLength={255}
                    aria-invalid={errors.name ? true : undefined}
                    aria-describedby={describedBy(ids.name, {
                        error: errors.name,
                    })}
                />
            </Field>

            <Field
                id={ids.email}
                label={t('admin.users.fields.email')}
                error={errors.email}
            >
                <Input
                    id={ids.email}
                    type="email"
                    value={data.email}
                    onChange={(event) => setData('email', event.target.value)}
                    required
                    autoComplete="off"
                    maxLength={255}
                    placeholder={t('common.email_placeholder')}
                    aria-invalid={errors.email ? true : undefined}
                    aria-describedby={describedBy(ids.email, {
                        error: errors.email,
                    })}
                />
            </Field>

            <Field
                id={ids.role}
                label={t('admin.users.fields.role')}
                error={errors.role}
                help={
                    canChangeRole
                        ? undefined
                        : t('admin.users.fields.role_locked')
                }
            >
                <NativeSelect
                    id={ids.role}
                    value={data.role}
                    disabled={!canChangeRole}
                    onChange={(event) =>
                        setData(
                            'role',
                            event.target.value as AdminAssignableRole,
                        )
                    }
                    aria-invalid={errors.role ? true : undefined}
                    aria-describedby={describedBy(ids.role, {
                        help: !canChangeRole,
                        error: errors.role,
                    })}
                >
                    {roleOptions.map((role) => (
                        <option key={role} value={role}>
                            {roleLabel(role)}
                        </option>
                    ))}
                </NativeSelect>
            </Field>

            <Field
                id={ids.department}
                label={t('admin.users.fields.department')}
                error={errors.department_id}
            >
                <NativeSelect
                    id={ids.department}
                    value={data.department_id}
                    onChange={(event) =>
                        setData('department_id', event.target.value)
                    }
                    aria-invalid={errors.department_id ? true : undefined}
                    aria-describedby={describedBy(ids.department, {
                        error: errors.department_id,
                    })}
                >
                    <option value="">
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
            </Field>

            <Field
                id={ids.jobTitle}
                label={t('admin.users.fields.job_title')}
                error={errors.job_title}
            >
                <Input
                    id={ids.jobTitle}
                    value={data.job_title}
                    onChange={(event) =>
                        setData('job_title', event.target.value)
                    }
                    autoComplete="off"
                    maxLength={120}
                    aria-invalid={errors.job_title ? true : undefined}
                    aria-describedby={describedBy(ids.jobTitle, {
                        error: errors.job_title,
                    })}
                />
            </Field>

            {showFinancials ? (
                <>
                    <Field
                        id={ids.cost}
                        label={t('admin.users.fields.hourly_cost')}
                        optional={t('admin.form.optional')}
                        help={t('admin.users.fields.hourly_cost_help')}
                        error={errors.hourly_cost}
                    >
                        <Input
                            id={ids.cost}
                            inputMode="decimal"
                            value={data.hourly_cost}
                            onChange={(event) =>
                                setData('hourly_cost', event.target.value)
                            }
                            placeholder="0,00"
                            className="tabular"
                            aria-invalid={errors.hourly_cost ? true : undefined}
                            aria-describedby={describedBy(ids.cost, {
                                help: true,
                                error: errors.hourly_cost,
                            })}
                        />
                    </Field>
                    <Field
                        id={ids.rate}
                        label={t('admin.users.fields.default_hourly_rate')}
                        optional={t('admin.form.optional')}
                        help={t('admin.users.fields.default_hourly_rate_help')}
                        error={errors.default_hourly_rate}
                    >
                        <Input
                            id={ids.rate}
                            inputMode="decimal"
                            value={data.default_hourly_rate}
                            onChange={(event) =>
                                setData(
                                    'default_hourly_rate',
                                    event.target.value,
                                )
                            }
                            placeholder="0,00"
                            className="tabular"
                            aria-invalid={
                                errors.default_hourly_rate ? true : undefined
                            }
                            aria-describedby={describedBy(ids.rate, {
                                help: true,
                                error: errors.default_hourly_rate,
                            })}
                        />
                    </Field>
                </>
            ) : null}
        </div>
    );
}

/** «45.00» (Laravel) → «45,00» para el campo; null → vacío. */
export function decimalToInput(value: string | null | undefined): string {
    return value ? value.replace('.', ',') : '';
}
