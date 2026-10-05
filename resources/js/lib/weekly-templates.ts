/**
 * Plantillas de los avisos de la weekly (10.5, F-104 y F-105): el gemelo de
 * App\Domain\Weeklies\Reminders\WeeklyTemplates::render. Sustituye {variable} por su valor y deja tal
 * cual las que no conoce. Los dos pasan tests/fixtures/weeklies/template-render.json.
 */
export function renderWeeklyTemplate(
    text: string,
    values: Record<string, string>,
): string {
    return text.replace(/\{([a-z_]+)\}/g, (match, key: string) =>
        Object.prototype.hasOwnProperty.call(values, key) ? values[key] : match,
    );
}

/** Saltos de línea de Unix y sin espacios al final, como lo guarda el servidor. */
export function normalizeWeeklyTemplate(text: string): string {
    return text.replace(/\r\n?/g, '\n').replace(/[ \t\n\r\0\v]+$/, '');
}
