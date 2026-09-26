import { usePage } from '@inertiajs/react';
import type { Abilities, Auth, Role, User } from '@/types';

/**
 * Acceso tipado a `auth` (props compartidas). `auth.user` es null en las páginas de invitado
 * (login, recuperación de contraseña…), así que los componentes comunes usan `useUser()`
 * y solo las páginas protegidas por el middleware `auth` usan `useRequiredUser()`.
 */

export function useAuth(): Auth {
    return usePage().props.auth;
}

export function useUser(): User | null {
    return useAuth().user;
}

export function useAbilities(): Abilities {
    return useAuth().can;
}

/** Para páginas bajo el middleware `auth`: el usuario siempre existe. */
export function useRequiredUser(): User {
    const user = useUser();

    if (!user) {
        throw new Error('Esta página requiere un usuario autenticado.');
    }

    return user;
}

export function isAuthenticated(auth: Auth): auth is Auth & { user: User } {
    return auth.user !== null;
}

export function hasRole(user: User | null, role: Role): boolean {
    return user?.roles.includes(role) ?? false;
}

/** Nombre de pila para saludos ("Hola, Ana"). */
export function firstName(user: Pick<User, 'name'>): string {
    return user.name.trim().split(/\s+/u)[0] ?? user.name;
}
