import { router } from '@inertiajs/react';
import { useState } from 'react';
import { GoogleLogo } from '@/components/auth/google-logo';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { google } from '@/routes/login';

/**
 * «Entrar con Google» (D-165), en /login y en la invitación de alta.
 *
 * POST: el servidor guarda en la sesión el `state`, el PKCE y el `nonce` y responde con la URL de
 * Google (Inertia::location), que Inertia abre en esta misma pestaña. Si Google no deja entrar, el
 * servidor vuelve a /login con el motivo en `errors.google`, que se muestra bajo el botón.
 *
 * Estilo plano de Audax (D-137) con el aspecto neutro que piden las directrices de Google: fondo
 * de tarjeta, borde de campo, texto normal y la «G» a color a la izquierda.
 */
export function GoogleSignInButton({
    remember = false,
    error,
    className,
}: {
    /** «Mantener la sesión iniciada» del formulario, si lo hay. */
    remember?: boolean;
    /** Motivo por el que Google no ha dejado entrar (errors.google). */
    error?: string;
    className?: string;
}) {
    const [processing, setProcessing] = useState(false);

    const start = () => {
        router.post(
            google.url(),
            { remember },
            {
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <div className={cn('grid gap-2', className)}>
            <Button
                type="button"
                variant="outline"
                className="w-full border-input bg-card text-foreground hover:bg-muted hover:text-foreground"
                onClick={start}
                disabled={processing}
                aria-describedby={error ? 'google-error' : undefined}
                data-test="google-login-button"
            >
                {processing ? <Spinner /> : <GoogleLogo className="size-4.5" />}
                {processing
                    ? t('login.google.opening')
                    : t('login.google.button')}
            </Button>
            <InputError id="google-error" message={error} />
        </div>
    );
}

/** Separador «o» entre el formulario y «Entrar con Google». */
export function AuthSeparator({ label }: { label: string }) {
    return (
        <div className="flex items-center gap-3 text-sm text-muted-foreground">
            <span aria-hidden="true" className="h-px flex-1 bg-border" />
            <span>{label}</span>
            <span aria-hidden="true" className="h-px flex-1 bg-border" />
        </div>
    );
}
