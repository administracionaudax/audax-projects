import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useRequiredUser } from '@/hooks/use-auth';
import { t } from '@/lib/i18n';
import { edit } from '@/routes/profile';
import type { ProfilePageProps } from '@/types';

// El borrado de la cuenta propia se eliminó en el contrato de la Fase 0: las bajas las gestiona
// un admin desactivando al usuario (SPEC §14: nunca se borra a quien tiene horas).
export default function Profile({ status, jobTitle = null }: ProfilePageProps) {
    const user = useRequiredUser();
    const [email, setEmail] = useState(user.email);
    // Cambiar el correo exige la contraseña actual (el backend la valida solo en ese caso).
    const emailChanged =
        email.trim().toLowerCase() !== user.email.toLowerCase();

    return (
        <>
            <Head title={t('profile.title')} />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('profile.heading')}
                    description={t('profile.description')}
                />

                {status && (
                    <p role="status" className="text-sm text-success">
                        {status}
                    </p>
                )}

                <Form
                    {...ProfileController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">
                                    {t('profile.name')}
                                </Label>

                                <Input
                                    id="name"
                                    className="mt-1 block w-full"
                                    defaultValue={user.name}
                                    name="name"
                                    required
                                    autoComplete="name"
                                    placeholder={t('profile.name_placeholder')}
                                    aria-invalid={
                                        errors.name ? true : undefined
                                    }
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.name}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="job_title">
                                    {t('profile.job_title')}
                                </Label>

                                <Input
                                    id="job_title"
                                    className="mt-1 block w-full"
                                    defaultValue={jobTitle ?? ''}
                                    name="job_title"
                                    maxLength={120}
                                    autoComplete="organization-title"
                                    placeholder={t(
                                        'profile.job_title_placeholder',
                                    )}
                                    aria-invalid={
                                        errors.job_title ? true : undefined
                                    }
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.job_title}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">
                                    {t('common.email')}
                                </Label>

                                <Input
                                    id="email"
                                    type="email"
                                    className="mt-1 block w-full"
                                    value={email}
                                    onChange={(event) =>
                                        setEmail(event.target.value)
                                    }
                                    name="email"
                                    required
                                    autoComplete="username"
                                    placeholder={t('common.email_placeholder')}
                                    aria-invalid={
                                        errors.email ? true : undefined
                                    }
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.email}
                                />
                            </div>

                            {emailChanged && (
                                <div className="grid gap-2">
                                    <Label htmlFor="current_password">
                                        {t('profile.current_password')}
                                    </Label>

                                    <PasswordInput
                                        id="current_password"
                                        name="current_password"
                                        className="mt-1 block w-full"
                                        required
                                        autoComplete="current-password"
                                        aria-describedby="current_password_help"
                                        aria-invalid={
                                            errors.current_password
                                                ? true
                                                : undefined
                                        }
                                    />

                                    <p
                                        id="current_password_help"
                                        className="text-sm text-muted-foreground"
                                    >
                                        {t('profile.current_password_help')}
                                    </p>

                                    <InputError
                                        className="mt-2"
                                        message={errors.current_password}
                                    />
                                </div>
                            )}

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-profile-button"
                                >
                                    {processing && <Spinner />}
                                    {t('common.save')}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

Profile.layout = {
    breadcrumbs: [{ title: t('profile.title'), href: edit() }],
};
