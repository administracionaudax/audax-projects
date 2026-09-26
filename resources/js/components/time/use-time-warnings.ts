import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import type { TimeEntryWarning } from '@/types';

function isWarning(value: unknown): value is TimeEntryWarning {
    return (
        typeof value === 'object' &&
        value !== null &&
        typeof (value as { message?: unknown }).message === 'string'
    );
}

/**
 * Avisos no bloqueantes de la imputación (tarea completada, jornada superada, exceso de bolsa)
 * que el servidor devuelve en el flash `time_warnings`: un toast de aviso por cada uno.
 * Se registra una sola vez, en la cabecera de la app.
 */
export function useTimeWarnings(): void {
    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash as
                | Record<string, unknown>
                | undefined;
            const warnings = flash?.time_warnings;

            if (!Array.isArray(warnings)) {
                return;
            }

            warnings.filter(isWarning).forEach((warning) => {
                toast.warning(warning.message);
            });
        });
    }, []);
}
