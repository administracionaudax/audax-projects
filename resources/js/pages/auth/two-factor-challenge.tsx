import { Form, Head, setLayoutProps } from '@inertiajs/react';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { OTP_MAX_LENGTH } from '@/hooks/use-two-factor-auth';
import { t } from '@/lib/i18n';
import { store } from '@/routes/two-factor/login';

export default function TwoFactorChallenge() {
    const [showRecoveryInput, setShowRecoveryInput] = useState<boolean>(false);
    const [code, setCode] = useState<string>('');

    const content = showRecoveryInput
        ? {
              title: t('two_factor.challenge.recovery_title'),
              description: t('two_factor.challenge.recovery_description'),
              toggleText: t('two_factor.challenge.use_code'),
          }
        : {
              title: t('two_factor.challenge.code_title'),
              description: t('two_factor.challenge.code_description'),
              toggleText: t('two_factor.challenge.use_recovery'),
          };

    setLayoutProps({
        title: content.title,
        description: content.description,
    });

    const toggleRecoveryMode = (clearErrors: () => void): void => {
        setShowRecoveryInput(!showRecoveryInput);
        clearErrors();
        setCode('');
    };

    return (
        <>
            <Head title={t('two_factor.challenge.page_title')} />

            <div className="space-y-6">
                <Form
                    {...store.form()}
                    className="space-y-4"
                    resetOnError
                    resetOnSuccess={!showRecoveryInput}
                >
                    {({ errors, processing, clearErrors }) => (
                        <>
                            {showRecoveryInput ? (
                                <div className="grid gap-2">
                                    <Label htmlFor="recovery_code">
                                        {t(
                                            'two_factor.challenge.recovery_label',
                                        )}
                                    </Label>
                                    <Input
                                        id="recovery_code"
                                        name="recovery_code"
                                        type="text"
                                        autoComplete="one-time-code"
                                        autoFocus={showRecoveryInput}
                                        required
                                        aria-invalid={
                                            errors.recovery_code
                                                ? true
                                                : undefined
                                        }
                                    />
                                    <InputError
                                        message={errors.recovery_code}
                                    />
                                </div>
                            ) : (
                                <div className="flex flex-col items-center justify-center space-y-3 text-center">
                                    <div className="flex w-full items-center justify-center">
                                        <InputOTP
                                            name="code"
                                            maxLength={OTP_MAX_LENGTH}
                                            value={code}
                                            onChange={(value) => setCode(value)}
                                            disabled={processing}
                                            pattern={REGEXP_ONLY_DIGITS}
                                            autoFocus
                                            aria-label={t(
                                                'two_factor.challenge.code_label',
                                            )}
                                        >
                                            <InputOTPGroup>
                                                {Array.from(
                                                    { length: OTP_MAX_LENGTH },
                                                    (_, index) => (
                                                        <InputOTPSlot
                                                            key={index}
                                                            index={index}
                                                        />
                                                    ),
                                                )}
                                            </InputOTPGroup>
                                        </InputOTP>
                                    </div>
                                    <InputError message={errors.code} />
                                </div>
                            )}

                            <Button
                                type="submit"
                                className="w-full"
                                disabled={processing}
                            >
                                {processing && <Spinner />}
                                {t('common.continue')}
                            </Button>

                            <p className="text-center text-sm text-muted-foreground">
                                <span>{t('two_factor.challenge.or')} </span>
                                <button
                                    type="button"
                                    className="cursor-pointer rounded-xs text-primary-text underline decoration-primary-text/40 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                    onClick={() =>
                                        toggleRecoveryMode(clearErrors)
                                    }
                                >
                                    {content.toggleText}
                                </button>
                            </p>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}
