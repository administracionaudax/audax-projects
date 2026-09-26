import { Section, Specimen } from '@/components/styleguide/section';
import { TimesheetBadge } from '@/components/styleguide/status-badges';
import type { TimesheetStatus } from '@/components/styleguide/status-badges';
import { FOCUS_RING } from '@/lib/focus-ring';
import { cn } from '@/lib/utils';
import { formatCurrency, formatDate, formatMinutes } from '@/lib/format';

type Row = {
    id: number;
    date: string;
    person: string;
    project: string;
    task: string;
    minutes: number;
    billable: boolean;
    amount: number;
    status: TimesheetStatus;
};

const ROWS: Row[] = [
    {
        id: 1,
        date: '2026-09-21',
        person: 'Laura Gómez',
        project: 'Web Hoteles Mediterráneo',
        task: 'Maquetación de la home',
        minutes: 390,
        billable: true,
        amount: 422.5,
        status: 'approved',
    },
    {
        id: 2,
        date: '2026-09-21',
        person: 'Marc Puig',
        project: 'App Clínica Dental Sonríe',
        task: 'Integración con la API de citas',
        minutes: 480,
        billable: true,
        amount: 520,
        status: 'approved',
    },
    {
        id: 3,
        date: '2026-09-22',
        person: 'Laura Gómez',
        project: 'Interno – Agencia',
        task: 'Reuniones',
        minutes: 60,
        billable: false,
        amount: 0,
        status: 'submitted',
    },
    {
        id: 4,
        date: '2026-09-22',
        person: 'Nerea Ibáñez',
        project: 'Campaña otoño Bodegas Lur',
        task: 'Anuncios de Meta',
        minutes: 225,
        billable: true,
        amount: 213.75,
        status: 'submitted',
    },
    {
        id: 5,
        date: '2026-09-23',
        person: 'Marc Puig',
        project: 'App Clínica Dental Sonríe',
        task: 'Corrección de errores',
        minutes: 150,
        billable: true,
        amount: 162.5,
        status: 'returned',
    },
    {
        id: 6,
        date: '2026-09-24',
        person: 'Nerea Ibáñez',
        project: 'Interno – Agencia',
        task: 'Formación',
        minutes: 120,
        billable: false,
        amount: 0,
        status: 'open',
    },
];

export function TableSection() {
    const totalMinutes = ROWS.reduce((sum, r) => sum + r.minutes, 0);
    const totalAmount = ROWS.reduce((sum, r) => sum + r.amount, 0);

    return (
        <Section
            id="tablas"
            title="Tablas"
            description="Densas y legibles: cuerpo de 14 px, cabecera en 500, filas alternas en surface-muted, cifras tabulares alineadas a la derecha. En móvil la tabla se desplaza dentro de su contenedor."
        >
            <Specimen title="Entradas de horas" className="p-0 sm:p-0">
                <div
                    className={cn('overflow-x-auto', FOCUS_RING)}
                    role="region"
                    aria-label="Entradas de horas"
                    tabIndex={0}
                >
                    <table className="w-full min-w-[46rem] text-sm">
                        <caption className="sr-only">
                            Entradas de horas de ejemplo
                        </caption>
                        <thead>
                            <tr className="border-b text-left">
                                <th
                                    scope="col"
                                    className="px-3 py-2 font-medium"
                                >
                                    Fecha
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-2 font-medium"
                                >
                                    Persona
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-2 font-medium"
                                >
                                    Proyecto y tarea
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-2 text-right font-medium"
                                >
                                    Horas
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-2 font-medium"
                                >
                                    Facturable
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-2 text-right font-medium"
                                >
                                    Importe
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-2 font-medium"
                                >
                                    Semana
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {ROWS.map((row) => (
                                <tr
                                    key={row.id}
                                    className="border-b even:bg-muted"
                                >
                                    <td className="tabular px-3 py-2 whitespace-nowrap">
                                        {formatDate(row.date)}
                                    </td>
                                    <td className="px-3 py-2 whitespace-nowrap">
                                        {row.person}
                                    </td>
                                    <td className="px-3 py-2">
                                        <span className="block">
                                            {row.task}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {row.project}
                                        </span>
                                    </td>
                                    <td className="tabular px-3 py-2 text-right">
                                        {formatMinutes(row.minutes)}
                                    </td>
                                    <td className="px-3 py-2">
                                        {row.billable ? 'Sí' : 'No'}
                                    </td>
                                    <td className="tabular px-3 py-2 text-right whitespace-nowrap">
                                        {row.billable
                                            ? formatCurrency(row.amount)
                                            : '—'}
                                    </td>
                                    <td className="px-3 py-2">
                                        <TimesheetBadge status={row.status} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr>
                                <th
                                    scope="row"
                                    colSpan={3}
                                    className="px-3 py-2 text-left font-medium"
                                >
                                    Total
                                </th>
                                <td className="tabular px-3 py-2 text-right font-medium">
                                    {formatMinutes(totalMinutes)}
                                </td>
                                <td />
                                <td className="tabular px-3 py-2 text-right font-medium whitespace-nowrap">
                                    {formatCurrency(totalAmount)}
                                </td>
                                <td />
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </Specimen>
        </Section>
    );
}
