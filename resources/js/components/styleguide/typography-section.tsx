import { Section, Specimen } from '@/components/styleguide/section';
import { formatCurrency, formatMinutes } from '@/lib/format';

const SCALE = [
    {
        name: 'Título de página',
        spec: '30 px / 36 · 400',
        className: 'text-3xl',
        sample: 'Proyectos activos',
    },
    {
        name: 'Título de sección',
        spec: '24 px / 32 · 400',
        className: 'text-2xl',
        sample: 'Horas de esta semana',
    },
    {
        name: 'Subtítulo',
        spec: '18 px / 28 · 400',
        className: 'text-lg',
        sample: 'Bolsa de Desarrollo · 2026',
    },
    {
        name: 'Cuerpo',
        spec: '15 px / 24 · 400',
        className: 'text-[15px] leading-6',
        sample: 'Imputa tus horas antes del viernes para que tu responsable pueda aprobar la semana.',
    },
    {
        name: 'Cuerpo en tablas',
        spec: '14 px / 20 · 400',
        className: 'text-sm',
        sample: 'Rediseño de la ficha de producto · Maquetación',
    },
    {
        name: 'Énfasis funcional',
        spec: '14 px / 20 · 500',
        className: 'text-sm font-medium',
        sample: 'Cabeceras de tabla, cifras de KPI, etiquetas activas',
    },
    {
        name: 'Secundario',
        spec: '12 px / 16 · 400 · muted-foreground',
        className: 'text-xs text-muted-foreground',
        sample: 'Actualizado el 26/09/2026 a las 14:05',
    },
];

const NUMBERS = [
    { label: 'Diseño UI', minutes: 1110, amount: 2331.5 },
    { label: 'Maquetación', minutes: 485, amount: 970 },
    { label: 'Reuniones', minutes: 90, amount: 0 },
];

export function TypographySection() {
    return (
        <Section
            id="tipografia"
            title="Tipografía"
            description="DM Sans autoalojada, pesos 400 y 500. El 400 es la base, también en titulares; el 500 solo para énfasis funcional. Nunca negritas."
        >
            <Specimen title="Pesos">
                <div className="grid gap-2 sm:grid-cols-2">
                    <p className="text-2xl">DM Sans 400 · Regular</p>
                    <p className="text-2xl font-medium">DM Sans 500 · Medium</p>
                </div>
            </Specimen>

            <Specimen
                title="Escala de la app"
                note="Reducida respecto a la web para pantallas densas."
            >
                <dl className="grid gap-5">
                    {SCALE.map((item) => (
                        <div
                            key={item.name}
                            className="grid gap-1 sm:grid-cols-[12rem_minmax(0,1fr)] sm:items-baseline sm:gap-6"
                        >
                            <dt className="text-xs text-muted-foreground">
                                {item.name}
                                <span className="tabular block">
                                    {item.spec}
                                </span>
                            </dt>
                            <dd className={item.className}>{item.sample}</dd>
                        </div>
                    ))}
                </dl>
            </Specimen>

            <Specimen
                title="Palabra clave en azul"
                note="Recurso de marca: una o varias palabras en azul Audax dentro de un titular navy. Solo en cabeceras de página y estados vacíos, y siempre a 24 px o más (el azul de marca da 4,37:1 sobre blanco: vale para texto grande, no para cuerpo)."
            >
                <p className="text-3xl text-brand-navy dark:text-foreground">
                    Carga de la{' '}
                    <span className="text-brand">semana que viene</span>
                </p>
            </Specimen>

            <Specimen
                title="Cifras tabulares"
                note="Clase .tabular en columnas de números (horas, importes, ejes). Las cifras grandes aisladas usan cifras proporcionales."
            >
                <table className="w-full max-w-md text-sm">
                    <caption className="sr-only">
                        Ejemplo de cifras tabulares
                    </caption>
                    <thead>
                        <tr className="border-b">
                            <th
                                scope="col"
                                className="py-1.5 text-left font-medium"
                            >
                                Tipo de tarea
                            </th>
                            <th
                                scope="col"
                                className="py-1.5 text-right font-medium"
                            >
                                Horas
                            </th>
                            <th
                                scope="col"
                                className="py-1.5 text-right font-medium"
                            >
                                Importe
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {NUMBERS.map((row) => (
                            <tr
                                key={row.label}
                                className="border-b last:border-0"
                            >
                                <th
                                    scope="row"
                                    className="py-1.5 text-left font-normal"
                                >
                                    {row.label}
                                </th>
                                <td className="tabular py-1.5 text-right">
                                    {formatMinutes(row.minutes)}
                                </td>
                                <td className="tabular py-1.5 text-right">
                                    {formatCurrency(row.amount)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </Specimen>
        </Section>
    );
}
