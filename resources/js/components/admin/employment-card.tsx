import { useForm } from '@inertiajs/react';
import { useId } from 'react';
import { DatePicker } from '@/components/domain/date-picker';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { t } from '@/lib/i18n';
import { update } from '@/routes/admin/users/employment';
import type { AdminEmployment } from '@/types';

/**
 * Datos laborales de la ficha de usuario (Fase 11, D-343; W-124 y W-125): fecha de alta y de baja y
 * si está sujeto al registro de jornada (si no, con un motivo). Solo para quien tiene manage-people.
 */
export function EmploymentCard({
    userId,
    employment,
}: {
    userId: number;
    employment: AdminEmployment;
}) {
    const id = useId();
    const form = useForm({
        hire_date: employment.hire_date ?? '',
        termination_date: employment.termination_date ?? '',
        subject_to_register: employment.subject_to_register,
        register_exemption_reason: employment.register_exemption_reason ?? '',
    });

    return (
        <Card data-test="employment-card">
            <CardHeader>
                <CardTitle>
                    <h2 className="text-base font-medium">
                        {t('people.employment.title')}
                    </h2>
                </CardTitle>
                <CardDescription>
                    {t('people.employment.description')}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form
                    className="grid gap-4"
                    noValidate
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            hire_date: data.hire_date || null,
                            termination_date: data.termination_date || null,
                            register_exemption_reason: data.subject_to_register
                                ? null
                                : data.register_exemption_reason,
                        }));
                        form.put(update.url(userId), { preserveScroll: true });
                    }}
                >
                    <div className="grid items-start gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor={`${id}-hire`}>
                                {t('people.employment.hire_date')}
                            </Label>
                            <DatePicker
                                id={`${id}-hire`}
                                value={form.data.hire_date || null}
                                onChange={(value) =>
                                    form.setData('hire_date', value ?? '')
                                }
                                invalid={Boolean(form.errors.hire_date)}
                            />
                            <InputError message={form.errors.hire_date} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={`${id}-termination`}>
                                {t('people.employment.termination_date')}
                            </Label>
                            <DatePicker
                                id={`${id}-termination`}
                                value={form.data.termination_date || null}
                                onChange={(value) =>
                                    form.setData(
                                        'termination_date',
                                        value ?? '',
                                    )
                                }
                                invalid={Boolean(form.errors.termination_date)}
                            />
                            <InputError
                                message={form.errors.termination_date}
                            />
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={form.data.subject_to_register}
                                onCheckedChange={(checked) =>
                                    form.setData(
                                        'subject_to_register',
                                        checked === true,
                                    )
                                }
                                data-test="employment-subject"
                            />
                            {t('people.employment.subject')}
                        </label>
                        <p className="text-xs text-muted-foreground">
                            {t('people.employment.subject_hint')}
                        </p>
                    </div>
                    {form.data.subject_to_register ? null : (
                        <div className="grid gap-2">
                            <Label htmlFor={`${id}-reason`}>
                                {t('people.employment.reason')}
                            </Label>
                            <Textarea
                                id={`${id}-reason`}
                                value={form.data.register_exemption_reason}
                                onChange={(event) =>
                                    form.setData(
                                        'register_exemption_reason',
                                        event.target.value,
                                    )
                                }
                                placeholder={t(
                                    'people.employment.reason_placeholder',
                                )}
                                maxLength={200}
                                aria-invalid={
                                    form.errors.register_exemption_reason
                                        ? true
                                        : undefined
                                }
                            />
                            <InputError
                                message={form.errors.register_exemption_reason}
                            />
                        </div>
                    )}
                    <div>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {t('common.save')}
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}
