import { useForm } from '@inertiajs/react';
import { UserPlus } from 'lucide-react';
import { useState } from 'react';
import { UserFormFields } from '@/components/admin/user-form-fields';
import type { UserFormData } from '@/components/admin/user-form-fields';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { store } from '@/routes/admin/users';
import type { AdminAssignableRole, Department } from '@/types';

const EMPTY: UserFormData = {
    name: '',
    email: '',
    role: 'employee',
    department_id: '',
    job_title: '',
    hourly_cost: '',
    default_hourly_rate: '',
};

/**
 * Alta por invitación (SPEC §14): crea la persona y le envía por correo el enlace para elegir su
 * contraseña. Al terminar, el servidor lleva a su ficha.
 */
export function InviteUserDialog({
    roles,
    departments,
    canGrantAdmin,
    showFinancials,
}: {
    roles: AdminAssignableRole[];
    departments: Department[];
    canGrantAdmin: boolean;
    showFinancials: boolean;
}) {
    const [open, setOpen] = useState(false);
    const form = useForm<UserFormData>({ ...EMPTY });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(store.url(), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setOpen(false);
            },
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (!next) {
                    form.clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>
                <Button data-test="invite-user">
                    <UserPlus aria-hidden="true" />
                    {t('admin.users.invite')}
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <form onSubmit={submit} className="grid gap-6" noValidate>
                    <DialogHeader>
                        <DialogTitle>
                            {t('admin.users.invite_title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('admin.users.invite_description')}
                        </DialogDescription>
                    </DialogHeader>

                    <UserFormFields
                        data={form.data}
                        setData={(key, value) => form.setData(key, value)}
                        errors={form.errors}
                        roles={roles}
                        departments={departments}
                        canGrantAdmin={canGrantAdmin}
                        showFinancials={showFinancials}
                    />

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button
                                type="button"
                                variant="secondary"
                                disabled={form.processing}
                            >
                                {t('common.cancel')}
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {t('admin.users.invite_submit')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
