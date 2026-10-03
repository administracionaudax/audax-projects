/** Valor de la cookie XSRF-TOKEN (Laravel la acepta en la cabecera X-XSRF-TOKEN). */
export function xsrfToken(): string | null {
    if (typeof document === 'undefined') {
        return null;
    }

    const cookie = document.cookie
        .split('; ')
        .find((item) => item.startsWith('XSRF-TOKEN='));

    return cookie
        ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length))
        : null;
}
