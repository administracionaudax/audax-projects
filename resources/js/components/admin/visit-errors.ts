import { toast } from 'sonner';
import { t } from '@/lib/i18n';

/**
 * Para las acciones sin formulario (router.post/delete de un botón o de un diálogo de
 * confirmación): si el servidor responde con errores de validación (p. ej. la página estaba
 * desfasada y ya no se puede borrar), los enseña en un aviso en lugar de perderlos.
 */
export function toastVisitErrors(errors: Record<string, unknown>): void {
    const messages = Object.values(errors).filter(
        (message): message is string =>
            typeof message === 'string' && message !== '',
    );

    toast.error(messages[0] ?? t('admin.errors.action_failed'));
}

/**
 * Errores de un formulario que no tienen campo donde pintarse (p. ej. «el previsto está
 * congelado» con la clave `forecast`): se avisan para que no se pierdan (D-310).
 */
export function toastUnshownErrors(
    errors: Record<string, unknown>,
    shown: readonly string[],
): void {
    const rest = Object.fromEntries(
        Object.entries(errors).filter(
            ([key]) =>
                !shown.some(
                    (field) => key === field || key.startsWith(`${field}.`),
                ),
        ),
    );

    if (Object.keys(rest).length > 0) {
        toastVisitErrors(rest);
    }
}
