import { Form, Head } from '@inertiajs/react';
import {
    AuthSeparator,
    GoogleSignInButton,
} from '@/components/auth/google-sign-in-button';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { store } from '@/routes/invitation';
import type { AcceptInvitationPageProps } from '@/types';

/**
 * Aceptar la invitación de alta (enlace del email, válido 7 días). Con un correo de la empresa,
 * también entrando con Google en lugar de crear una contraseña (D-165).
 */

export default function AcceptInvitation({
    token,
    email,
    passwordRules,
    googleLogin = false,
}: AcceptInvitationPageProps) {
    return (
        <>
            <Head title={t('invitation.page_title')} />

            <Form
                {...store.form()}
                transform={(data) => ({ ...data, token, email })}
                resetOnSuccess={['password', 'password_confirmation']}
            >
                {({ processing, errors }) => (
                    <div className="grid gap-6">
                        <div className="grid gap-2">
                            <Label htmlFor="email">{t('common.email')}</Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                autoComplete="email"
                                value={email}
                                className="mt-1 block w-full"
                                readOnly
                            />
                            <InputError
                                message={errors.email}
                                className="mt-2"
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password">
                                {t('invitation.password')}
                            </Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                autoComplete="new-password"
                                className="mt-1 block w-full"
                                autoFocus
                                passwordrules={passwordRules}
                                aria-invalid={
                                    errors.password ? true : undefined
                                }
                            />
                            <InputError message={errors.password} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password_confirmation">
                                {t('common.password_confirmation')}
                            </Label>
                            <PasswordInput
                                id="password_confirmation"
                                name="password_confirmation"
                                autoComplete="new-password"
                                className="mt-1 block w-full"
                                passwordrules={passwordRules}
                                aria-invalid={
                                    errors.password_confirmation
                                        ? true
                                        : undefined
                                }
                            />
                            <InputError
                                message={errors.password_confirmation}
                                className="mt-2"
                            />
                        </div>

                        <Button
                            type="submit"
                            className="mt-4 w-full"
                            disabled={processing}
                            data-test="accept-invitation-button"
                        >
                            {processing && <Spinner />}
                            {t('invitation.submit')}
                        </Button>
                    </div>
                )}
            </Form>

            {googleLogin && (
                <div className="grid gap-6">
                    <AuthSeparator label={t('invitation.google.separator')} />
                    <GoogleSignInButton />
                </div>
            )}
        </>
    );
}

AcceptInvitation.layout = {
    title: t('invitation.title'),
    description: t('invitation.description'),
};
