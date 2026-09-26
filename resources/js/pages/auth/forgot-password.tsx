import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { login } from '@/routes';
import { email } from '@/routes/password';
import type { ForgotPasswordPageProps } from '@/types';

export default function ForgotPassword({ status }: ForgotPasswordPageProps) {
    return (
        <>
            <Head title={t('forgot.page_title')} />

            {status && (
                <p
                    role="status"
                    className="rounded-md bg-success-soft px-3 py-2 text-sm text-success"
                >
                    {status}
                </p>
            )}

            <div className="space-y-6">
                <Form {...email.form()}>
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="email">
                                    {t('common.email')}
                                </Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    autoComplete="email"
                                    required
                                    autoFocus
                                    placeholder={t('common.email_placeholder')}
                                    aria-invalid={
                                        errors.email ? true : undefined
                                    }
                                />

                                <InputError message={errors.email} />
                            </div>

                            <div className="my-6 flex items-center justify-start">
                                <Button
                                    className="w-full"
                                    disabled={processing}
                                    data-test="email-password-reset-link-button"
                                >
                                    {processing && <Spinner />}
                                    {t('forgot.submit')}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                <p className="space-x-1 text-sm text-muted-foreground">
                    <span>{t('forgot.back_prefix')}</span>
                    <TextLink href={login()}>{t('forgot.back_link')}</TextLink>
                </p>
            </div>
        </>
    );
}

ForgotPassword.layout = {
    title: t('forgot.title'),
    description: t('forgot.description'),
};
