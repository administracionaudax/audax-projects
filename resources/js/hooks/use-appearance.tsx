import { useSyncExternalStore } from 'react';
import type { ThemePreference } from '@/types';

/**
 * Tema claro/oscuro/según el sistema (SPEC §3).
 * - En el cliente se guarda en localStorage y en la cookie `appearance` (la lee Blade para
 *   pintar la clase `dark` antes de hidratar y evitar el parpadeo).
 * - La preferencia del usuario vive en `users.theme_preference` y manda: al cargar, si difiere
 *   de la guardada en el navegador, se aplica la del usuario (ver `syncAppearance`).
 */

export type ResolvedAppearance = 'light' | 'dark';
export type Appearance = ThemePreference;

export type UseAppearanceReturn = {
    readonly appearance: Appearance;
    readonly resolvedAppearance: ResolvedAppearance;
    readonly updateAppearance: (mode: Appearance) => void;
};

const STORAGE_KEY = 'appearance';

const listeners = new Set<() => void>();
let currentAppearance: Appearance = 'system';

export function isAppearance(value: unknown): value is Appearance {
    return value === 'light' || value === 'dark' || value === 'system';
}

const prefersDark = (): boolean => {
    if (typeof window === 'undefined' || !window.matchMedia) {
        return false;
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches;
};

const setCookie = (name: string, value: string, days = 365): void => {
    if (typeof document === 'undefined') {
        return;
    }

    const maxAge = days * 24 * 60 * 60;
    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
};

const getStoredAppearance = (): Appearance => {
    if (typeof window === 'undefined') {
        return 'system';
    }

    const stored = localStorage.getItem(STORAGE_KEY);

    return isAppearance(stored) ? stored : 'system';
};

const isDarkMode = (appearance: Appearance): boolean => {
    return appearance === 'dark' || (appearance === 'system' && prefersDark());
};

const applyTheme = (appearance: Appearance): void => {
    if (typeof document === 'undefined') {
        return;
    }

    const isDark = isDarkMode(appearance);

    document.documentElement.classList.toggle('dark', isDark);
    document.documentElement.style.colorScheme = isDark ? 'dark' : 'light';
};

const subscribe = (callback: () => void) => {
    listeners.add(callback);

    return () => listeners.delete(callback);
};

const notify = (): void => listeners.forEach((listener) => listener());

const mediaQuery = (): MediaQueryList | null => {
    if (typeof window === 'undefined' || !window.matchMedia) {
        return null;
    }

    return window.matchMedia('(prefers-color-scheme: dark)');
};

const handleSystemThemeChange = (): void => applyTheme(currentAppearance);

/** Guarda el tema en el navegador y lo aplica (sin tocar el servidor). */
export function setAppearance(mode: Appearance): void {
    currentAppearance = mode;

    if (typeof window !== 'undefined') {
        localStorage.setItem(STORAGE_KEY, mode);
    }

    setCookie(STORAGE_KEY, mode);
    applyTheme(mode);
    notify();
}

/**
 * Aplica la preferencia guardada en el usuario si difiere de la del navegador.
 * Devuelve true si ha cambiado algo.
 */
export function syncAppearance(
    preference: Appearance | null | undefined,
): boolean {
    if (!isAppearance(preference) || preference === getStoredAppearance()) {
        return false;
    }

    setAppearance(preference);

    return true;
}

export function initializeTheme(): void {
    if (typeof window === 'undefined') {
        return;
    }

    if (!isAppearance(localStorage.getItem(STORAGE_KEY))) {
        localStorage.setItem(STORAGE_KEY, 'system');
        setCookie(STORAGE_KEY, 'system');
    }

    currentAppearance = getStoredAppearance();
    applyTheme(currentAppearance);

    mediaQuery()?.addEventListener('change', handleSystemThemeChange);
}

export function useAppearance(): UseAppearanceReturn {
    const appearance: Appearance = useSyncExternalStore(
        subscribe,
        () => currentAppearance,
        () => 'system',
    );

    const resolvedAppearance: ResolvedAppearance = isDarkMode(appearance)
        ? 'dark'
        : 'light';

    return {
        appearance,
        resolvedAppearance,
        updateAppearance: setAppearance,
    } as const;
}
