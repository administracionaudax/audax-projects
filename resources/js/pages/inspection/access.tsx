import { Head, router } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import { useId, useState } from 'react';
import { InspectionShell } from '@/components/inspection/inspection-shell';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { accessStateLabel } from '@/lib/people-register';
import { login } from '@/routes/inspection';
import type { InspectionAccessState } from '@/types/people-register';

/**
 * Entrada de la Inspección de Trabajo (D-353): el enlace ya identifica el acceso; aquí se pide el
 * código (el segundo factor, que RR. HH. entrega por otra vía). A los 5 códigos mal puestos se
 * bloquea.
 */
export default function InspectionAccessPage({
    token,
    name,
    usable,
    state,
}: {
    token: string;
    name: string;
    usable: boolean;
    state: InspectionAccessState;
}) {
    const id = useId();
    const [code, setCode] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);

    return (
        <InspectionShell>
            <Head title={t('people.portal.access_title')} />
            <section
                aria-labelledby={`${id}-title`}
                className="mx-auto grid w-full max-w-md gap-4 rounded-md border p-6"
            >
                <div className="space-y-1">
                    <h1
                        id={`${id}-title`}
                        className="text-2xl font-normal tracking-tight"
                    >
                        {t('people.portal.access_title')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('people.portal.access_hint', { name })}
                    </p>
                </div>
                {usable ? (
                    <form
                        className="grid gap-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            router.post(
                                login.url(token),
                                { code },
                                {
                                    onStart: () => setProcessing(true),
                                    onFinish: () => setProcessing(false),
                                    onError: (errors) => setError(errors.code),
                                },
                            );
                        }}
                    >
                        <div className="grid gap-1.5">
                            <Label htmlFor={`${id}-code`}>
                                {t('people.portal.code')}
                            </Label>
                            <Input
                                id={`${id}-code`}
                                value={code}
                                onChange={(event) =>
                                    setCode(event.target.value)
                                }
                                autoComplete="one-time-code"
                                inputMode="numeric"
                                placeholder="0000-0000"
                                required
                                aria-invalid={error ? true : undefined}
                                className="tabular"
                                data-test="inspection-code"
                            />
                            <InputError message={error} />
                        </div>
                        <Button
                            type="submit"
                            disabled={processing || code.trim() === ''}
                            data-test="inspection-enter"
                        >
                            {processing ? (
                                <Spinner />
                            ) : (
                                <KeyRound aria-hidden="true" />
                            )}
                            {t('people.portal.enter')}
                        </Button>
                    </form>
                ) : (
                    <p
                        className="rounded-md bg-warning-soft p-3 text-sm"
                        role="status"
                    >
                        {t('people.portal.unavailable', {
                            state: accessStateLabel(state),
                        })}
                    </p>
                )}
            </section>
        </InspectionShell>
    );
}
