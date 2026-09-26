/**
 * Traducciones de la interfaz (SPEC §15: textos en ficheros de idioma, `es` completo).
 *
 * - Fuentes:
 *   · `lang/es.json`: textos de la Fase 0 y claves del backend (`__()` de Laravel lo lee),
 *   · `lang/ui/*.json`: textos del frontend por área desde la Fase 1 (shared, admin, clients,
 *     projects, hour-banks, tasks, time y notifications). Laravel no los lee.
 *   Una clave solo puede estar en un fichero (tests/js/i18n.test.ts).
 * - Se importan en la compilación (Vite los incrusta en el bundle): no hay petición en runtime.
 * - Claves del frontend: semánticas, en inglés y con puntos (`nav.projects`, `login.title`).
 *   Nunca empiezan por `auth.`, `pagination.`, `passwords.`, `validation.` ni `time.`, para no
 *   pisar los grupos PHP de `lang/es/*.php` (`__('auth.failed')` busca primero en el JSON).
 * - Claves del backend: el texto de origen en inglés, como hace Laravel (`"Profile updated."`),
 *   o grupos PHP en `lang/es/*.php` (`__('time.errors.future_date')`).
 * - Reemplazos al estilo Laravel: `:name` → valor, `:Name` → primera letra en mayúscula,
 *   `:NAME` → todo en mayúsculas.
 */
import base from '../../../lang/es.json';
import admin from '../../../lang/ui/admin.json';
import clients from '../../../lang/ui/clients.json';
import hourBanks from '../../../lang/ui/hour-banks.json';
import notifications from '../../../lang/ui/notifications.json';
import projects from '../../../lang/ui/projects.json';
import shared from '../../../lang/ui/shared.json';
import tasks from '../../../lang/ui/tasks.json';
import time from '../../../lang/ui/time.json';

const messages = {
    ...base,
    ...shared,
    ...admin,
    ...clients,
    ...projects,
    ...hourBanks,
    ...tasks,
    ...time,
    ...notifications,
};

export type TranslationKey = keyof typeof messages;

export type Replacements = Record<string, string | number>;

const dictionary: Readonly<Record<string, string>> = messages;

function capitalize(value: string): string {
    return value.charAt(0).toUpperCase() + value.slice(1);
}

/** Traduce una clave de `lang/es.json`. Si no existe, devuelve la propia clave. */
export function t(key: TranslationKey, replacements?: Replacements): string {
    let line = dictionary[key] ?? key;

    if (!replacements) {
        return line;
    }

    // Primero las claves más largas, para que `:name` no se coma `:names`.
    const names = Object.keys(replacements).sort((a, b) => b.length - a.length);

    for (const name of names) {
        const value = String(replacements[name]);

        line = line
            .replaceAll(`:${name.toUpperCase()}`, value.toUpperCase())
            .replaceAll(`:${capitalize(name)}`, capitalize(value))
            .replaceAll(`:${name}`, value);
    }

    return line;
}

/** Comprueba si una clave dinámica existe (útil con valores que llegan del servidor). */
export function hasTranslation(key: string): key is TranslationKey {
    return Object.hasOwn(dictionary, key);
}
