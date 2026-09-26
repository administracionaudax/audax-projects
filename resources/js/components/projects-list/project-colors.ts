import type { TranslationKey } from '@/lib/i18n';

/**
 * Paleta de colores de proyecto: la categórica de marca (D-012), en su orden fijo.
 * Gemela de App\Domain\Projects\ProjectColors::PALETTE (el servidor solo admite estos).
 */
export const PROJECT_COLORS: ReadonlyArray<{
    value: string;
    label: TranslationKey;
}> = [
    { value: '#0171FF', label: 'projects.colors.blue' },
    { value: '#179FA5', label: 'projects.colors.turquoise' },
    { value: '#5E2DAD', label: 'projects.colors.violet' },
    { value: '#E65FB3', label: 'projects.colors.magenta' },
    { value: '#3C41AE', label: 'projects.colors.indigo' },
    { value: '#0892C4', label: 'projects.colors.sky' },
];
