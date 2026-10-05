import { Form, Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    AuthSeparator,
    GoogleSignInButton,
} from '@/components/auth/google-sign-in-button';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import type { LoginPageProps } from '@/types';

// Sin registro público (D-008): las cuentas las crea un admin por invitación.
// «Entrar con Google» (D-165) solo si el servidor tiene credenciales y el ajuste está activado.
export default function Login({
    status,
    canResetPassword,
    googleLogin = false,
}: LoginPageProps) {
    const [remember, setRemember] = useState(false);
    const googleError = usePage<{ errors?: Record<string, string> }>().props
        .errors?.google;

    return (
        <>
            <Head title={t('login.page_title')} />

            {status && (
                <p
                    role="status"
                    className="rounded-md bg-success-soft px-3 py-2 text-sm text-success"
                >
                    {status}
                </p>
            )}

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <div className="grid gap-6">
                        <div className="grid gap-2">
                            <Label htmlFor="email">{t('common.email')}</Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                required
                                autoFocus
                                autoComplete="email"
                                placeholder={t('common.email_placeholder')}
                                aria-invalid={errors.email ? true : undefined}
                                aria-describedby={
                                    errors.email ? 'email-error' : undefined
                                }
                            />
                            <InputError
                                id="email-error"
                                message={errors.email}
                            />
                        </div>

                        <div className="grid gap-2">
                            <div className="flex items-center">
                                <Label htmlFor="password">
                                    {t('common.password')}
                                </Label>
                                {canResetPassword && (
                                    <TextLink
                                        href={request()}
                                        className="ml-auto text-sm"
                                    >
                                        {t('login.forgot_password')}
                                    </TextLink>
                                )}
                            </div>
                            <PasswordInput
                                id="password"
                                name="password"
                                required
                                autoComplete="current-password"
                                aria-invalid={
                                    errors.password ? true : undefined
                                }
                                aria-describedby={
                                    errors.password
                                        ? 'password-error'
                                        : undefined
                                }
                            />
                            <InputError
                                id="password-error"
                                message={errors.password}
                            />
                        </div>

                        <div className="flex items-center space-x-3">
                            <Checkbox
                                id="remember"
                                name="remember"
                                checked={remember}
                                onCheckedChange={(checked) =>
                                    setRemember(checked === true)
                                }
                            />
                            <Label htmlFor="remember">
                                {t('login.remember')}
                            </Label>
                        </div>

                        <Button
                            type="submit"
                            className="mt-2 w-full"
                            disabled={processing}
                            data-test="login-button"
                        >
                            {processing && <Spinner />}
                            {t('login.submit')}
                        </Button>
                    </div>
                )}
            </Form>

            {googleLogin && (
                <div className="grid gap-6">
                    <AuthSeparator label={t('login.google.separator')} />
                    <GoogleSignInButton
                        remember={remember}
                        error={googleError}
                    />
                </div>
            )}
        </>
    );
}

Login.layout = {
    title: t('login.title'),
    description: t('login.description'),
};
