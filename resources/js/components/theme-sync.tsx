import { useEffect } from 'react';
import { useUser } from '@/hooks/use-auth';
import { syncAppearance } from '@/hooks/use-appearance';

/**
 * Aplica `auth.user.theme_preference` cuando difiere del tema guardado en el navegador
 * (p. ej., el usuario lo cambió en otro dispositivo). Se monta en los layouts autenticados.
 */
export function ThemeSync() {
    const preference = useUser()?.theme_preference;

    useEffect(() => {
        syncAppearance(preference);
    }, [preference]);

    return null;
}
