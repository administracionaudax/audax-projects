import { useForm } from '@inertiajs/react';
import { TriangleAlert, UserPlus } from 'lucide-react';
import { useId, useState } from 'react';
import { describedBy, Field } from '@/components/admin/field';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { store } from '@/routes/clients/portal/users';

type InviteForm = { name: string; email: string };

/**
 * «Invitar al portal» (D-063): nombre y correo. El servidor crea el usuario del cliente y le envía
 * el enlace de 7 días; los errores (correo repetido, de una persona del equipo, cliente desactivado)
 * se ven junto al campo o arriba del formulario.
 */
export function InvitePortalUserDialog({
    clientId,
    clientName,
    disabled = false,
}: {
    clientId: number;
    clientName: string;
    disabled?: boolean;
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const form = useForm<InviteForm>({ name: '', email: '' });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (next) {
                    form.reset();
                    form.clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>
                <Button
                    variant="outline"
                    disabled={disabled}
                    data-test="portal-invite"
                >
                    <UserPlus aria-hidden="true" />
                    {t('portal_access.invite.button')}
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-lg">
                <form
                    noValidate
                    className="grid gap-6"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.submit(store(clientId), {
                            preserveScroll: true,
                            onSuccess: () => setOpen(false),
                        });
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {t('portal_access.invite.title', {
                                client: clientName,
                            })}
                        </DialogTitle>
                        <DialogDescription>
                            {t('portal_access.invite.description')}
                        </DialogDescription>
                    </DialogHeader>

                    {errors.client ? (
                        <Alert variant="destructive" role="alert">
                            <TriangleAlert aria-hidden="true" />
                            <AlertDescription>{errors.client}</AlertDescription>
                        </Alert>
                    ) : null}

                    <div className="grid gap-5">
                        <Field
                            id={`${id}-name`}
                            label={t('portal_access.invite.name')}
                            error={errors.name}
                        >
                            <Input
                                id={`${id}-name`}
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                required
                                maxLength={255}
                                autoComplete="off"
                                aria-invalid={errors.name ? true : undefined}
                                aria-describedby={describedBy(`${id}-name`, {
                                    error: errors.name,
                                })}
                            />
                        </Field>
                        <Field
                            id={`${id}-email`}
                            label={t('portal_access.invite.email')}
                            help={t('portal_access.invite.email_help')}
                            error={errors.email}
                        >
                            <Input
                                id={`${id}-email`}
                                type="email"
                                value={form.data.email}
                                onChange={(event) =>
                                    form.setData('email', event.target.value)
                                }
                                required
                                maxLength={255}
                                autoComplete="off"
                                aria-invalid={errors.email ? true : undefined}
                                aria-describedby={describedBy(`${id}-email`, {
                                    help: true,
                                    error: errors.email,
                                })}
                            />
                        </Field>
                    </div>

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
                        <Button
                            type="submit"
                            disabled={form.processing}
                            data-test="portal-invite-submit"
                        >
                            {form.processing ? <Spinner /> : null}
                            {t('portal_access.invite.submit')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
