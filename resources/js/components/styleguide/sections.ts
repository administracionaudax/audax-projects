/** Secciones de /styleguide, en orden (el id es el ancla). */
export const STYLEGUIDE_SECTIONS = [
    { id: 'colores', label: 'Colores' },
    { id: 'tipografia', label: 'Tipografía' },
    { id: 'botones', label: 'Botones' },
    { id: 'formularios', label: 'Formularios' },
    { id: 'tablas', label: 'Tablas' },
    { id: 'tarjetas-y-badges', label: 'Tarjetas y badges' },
    { id: 'semaforo-de-carga', label: 'Semáforo de carga' },
    { id: 'bolsas-de-horas', label: 'Bolsas de horas' },
    { id: 'graficas', label: 'Gráficas' },
    { id: 'estados', label: 'Estados y avisos' },
] as const;

export type StyleguideSectionId = (typeof STYLEGUIDE_SECTIONS)[number]['id'];
