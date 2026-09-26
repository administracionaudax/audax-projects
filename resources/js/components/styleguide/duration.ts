/**
 * Ejemplo de interpretación de duraciones para el campo de horas (SPEC §7):
 * "1:30", "1.5", "1,5", "90m", "1h30", "1h 30m", "2h" y "2" (horas).
 * Devuelve minutos enteros o null si el texto no es una duración válida.
 */
export function parseDuration(input: string): number | null {
    const text = input.trim().toLowerCase().replace(/\s+/g, '');

    if (text === '') {
        return null;
    }

    let minutes: number | null = null;
    let match: RegExpExecArray | null;

    if ((match = /^(\d{1,2}):([0-5]\d)$/.exec(text))) {
        minutes = Number(match[1]) * 60 + Number(match[2]);
    } else if ((match = /^(\d+)(?:m|min)$/.exec(text))) {
        minutes = Number(match[1]);
    } else if ((match = /^(\d+)h(?:(\d{1,2})(?:m|min)?)?$/.exec(text))) {
        minutes = Number(match[1]) * 60 + Number(match[2] ?? 0);
    } else if ((match = /^(\d+(?:[.,]\d+)?)h?$/.exec(text))) {
        minutes = Math.round(Number(match[1].replace(',', '.')) * 60);
    }

    if (
        minutes === null ||
        !Number.isFinite(minutes) ||
        minutes <= 0 ||
        minutes > 24 * 60
    ) {
        return null;
    }

    return minutes;
}
