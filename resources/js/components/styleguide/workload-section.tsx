import { LoadCell } from '@/components/charts/load-cell';
import { LOAD_LEVELS } from '@/components/charts/thresholds';
import type { LoadLevel } from '@/components/charts/thresholds';
import { Section, Specimen } from '@/components/styleguide/section';

const LEGEND: {
    level: LoadLevel;
    range: string;
    planned: number;
    capacity: number;
    reason?: string;
}[] = [
    {
        level: 'none',
        range: 'Sin capacidad (vacaciones o festivo)',
        planned: 0,
        capacity: 0,
        reason: 'Festivo',
    },
    {
        level: 'under',
        range: 'Por debajo del 70 %',
        planned: 240,
        capacity: 480,
    },
    {
        level: 'balanced',
        range: 'Entre el 70 % y el 100 %',
        planned: 390,
        capacity: 480,
    },
    {
        level: 'high',
        range: 'Entre el 100 % y el 120 %',
        planned: 540,
        capacity: 480,
    },
    {
        level: 'over',
        range: 'Por encima del 120 %',
        planned: 660,
        capacity: 480,
    },
];

const DAYS = ['Lun 28/09', 'Mar 29/09', 'Mié 30/09', 'Jue 01/10', 'Vie 02/10'];

const PEOPLE: {
    name: string;
    department: string;
    cells: [number, number, string?][];
}[] = [
    {
        name: 'Laura Gómez',
        department: 'Diseño',
        cells: [
            [390, 480],
            [480, 480],
            [600, 480],
            [420, 480],
            [180, 420],
        ],
    },
    {
        name: 'Marc Puig',
        department: 'Desarrollo',
        cells: [
            [540, 480],
            [720, 480],
            [510, 480],
            [450, 480],
            [0, 0, 'Vacaciones'],
        ],
    },
    {
        name: 'Nerea Ibáñez',
        department: 'Marketing',
        cells: [
            [120, 480],
            [300, 480],
            [360, 480],
            [0, 0, 'Festivo'],
            [240, 420],
        ],
    },
];

export function WorkloadSection() {
    return (
        <Section
            id="semaforo-de-carga"
            title="Semáforo de carga"
            description="Horas planificadas frente a capacidad (SPEC §9). Cada celda lleva icono, cifras y porcentaje: el color nunca va solo."
        >
            <Specimen title="Niveles">
                <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {LEGEND.map((item) => (
                        <li
                            key={item.level}
                            className="grid grid-cols-[7rem_minmax(0,1fr)] items-center gap-3"
                        >
                            <LoadCell
                                planned={item.planned}
                                capacity={item.capacity}
                                reason={item.reason}
                            />
                            <span className="text-sm">
                                <span className="block font-medium">
                                    {LOAD_LEVELS[item.level].label}
                                </span>
                                <span className="block text-muted-foreground">
                                    {item.range}
                                </span>
                            </span>
                        </li>
                    ))}
                </ul>
            </Specimen>

            <Specimen
                title="Matriz personas × días"
                note="Semana que viene. En móvil, la matriz se desplaza dentro de su contenedor."
                className="p-0 sm:p-0"
            >
                <div
                    className="overflow-x-auto outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    role="region"
                    aria-label="Matriz de carga"
                    tabIndex={0}
                >
                    <table className="w-full min-w-[40rem] border-separate border-spacing-1 text-sm">
                        <caption className="sr-only">
                            Carga planificada de la semana que viene por persona
                            y día
                        </caption>
                        <thead>
                            <tr>
                                <th
                                    scope="col"
                                    className="px-2 py-1 text-left font-medium"
                                >
                                    Persona
                                </th>
                                {DAYS.map((day) => (
                                    <th
                                        key={day}
                                        scope="col"
                                        className="px-2 py-1 text-left font-medium whitespace-nowrap"
                                    >
                                        {day}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {PEOPLE.map((person) => (
                                <tr key={person.name}>
                                    <th
                                        scope="row"
                                        className="px-2 py-1 text-left font-normal whitespace-nowrap"
                                    >
                                        <span className="block">
                                            {person.name}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {person.department}
                                        </span>
                                    </th>
                                    {person.cells.map(
                                        (
                                            [planned, capacity, reason],
                                            index,
                                        ) => (
                                            <td key={DAYS[index]}>
                                                <LoadCell
                                                    planned={planned}
                                                    capacity={capacity}
                                                    reason={reason}
                                                />
                                            </td>
                                        ),
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Specimen>
        </Section>
    );
}
