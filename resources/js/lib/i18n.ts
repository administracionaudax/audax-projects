/**
 * Traducciones de la interfaz (SPEC §15: textos en ficheros de idioma, `es` completo).
 *
 * - Fuente única: `lang/es.json`, el mismo fichero que usa Laravel para `__()`.
 *   Se importa en la compilación (Vite lo incrusta en el bundle): no hay petición ni coste en runtime.
 * - Claves del frontend: semánticas, en inglés y con puntos (`nav.projects`, `login.title`).
 *   Nunca empiezan por `auth.`, `pagination.`, `passwords.` ni `validation.`, para no pisar
 *   los grupos PHP de Laravel (`__('auth.failed')` busca primero en el JSON).
 * - Claves del backend: el texto de origen en inglés, como hace Laravel (`"Profile updated."`).
 * - Reemplazos al estilo Laravel: `:name` → valor, `:Name` → primera letra en mayúscula,
 *   `:NAME` → todo en mayúsculas.
 */
import messages from '../../../lang/es.json';

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
