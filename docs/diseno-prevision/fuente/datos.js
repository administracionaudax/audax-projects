/*
 * Datos de ejemplo de las maquetas de la Previsión (docs/DISENO-PREVISION.md).
 * Escala de Audax: 30 personas en 4 departamentos, 12 meses (oct 2026 – sep 2027),
 * 12 proyectos reales con asignaciones y 5 previstos (2 seguros y 3 posibles), con huecos
 * de departamento sin persona y alguna sobrecarga. Hoy = martes 6 de octubre de 2026.
 *
 * Reglas (PLAN-CARGAS §6 y §15): la carga sale SOLO de las asignaciones (P6, P7); la
 * capacidad = jornada − festivos − ausencias; un hueco es demanda del departamento, no
 * capacidad. Seguridad: «seguro» o «posible», sin % (P5).
 */
const PV = (() => {
    const TODAY = '2026-10-06';
    const START = '2026-10-05'; // lunes de esta semana
    const DAYS_IN_HORIZON = 364; // 52 semanas

    const DEPTS = [
        { id: 'dis', name: 'Diseño' },
        { id: 'dev', name: 'Desarrollo' },
        { id: 'mkt', name: 'Marketing' },
        { id: 'cue', name: 'Cuentas' },
    ];

    // [id, nombre, departamento, horas al día]
    const PEOPLE_RAW = [
        ['ana', 'Ana López', 'dis', 8],
        ['luis', 'Luis Martín', 'dis', 8],
        ['sara', 'Sara Ruiz', 'dis', 6],
        ['marta', 'Marta Gil', 'dis', 8],
        ['pablo', 'Pablo Ortega', 'dis', 8],
        ['irene', 'Irene Navarro', 'dis', 8],
        ['carlos', 'Carlos Vidal', 'dis', 8],
        ['lucia', 'Lucía Herrera', 'dis', 8],
        ['javier', 'Javier Molina', 'dis', 8],
        ['elena', 'Elena Castro', 'dis', 8],
        ['nuria', 'Nuria Prieto', 'dis', 8],
        ['diego', 'Diego Romero', 'dev', 8],
        ['raul', 'Raúl Serrano', 'dev', 8],
        ['clara', 'Clara Domínguez', 'dev', 8],
        ['andres', 'Andrés Peña', 'dev', 8],
        ['sergio', 'Sergio Fuentes', 'dev', 8],
        ['laura', 'Laura Iglesias', 'dev', 8],
        ['hugo', 'Hugo Cano', 'dev', 8],
        ['alba', 'Alba Rubio', 'dev', 5],
        ['ivan', 'Iván Lozano', 'dev', 8],
        ['paula', 'Paula Medina', 'mkt', 8],
        ['alvaro', 'Álvaro Garrido', 'mkt', 8],
        ['cristina', 'Cristina Vega', 'mkt', 8],
        ['rocio', 'Rocío Calvo', 'mkt', 8],
        ['daniel', 'Daniel Reyes', 'mkt', 8],
        ['noelia', 'Noelia Santos', 'mkt', 8],
        ['beatriz', 'Beatriz Moreno', 'cue', 8],
        ['jorge', 'Jorge Delgado', 'cue', 8],
        ['silvia', 'Silvia Ramos', 'cue', 8],
        ['tomas', 'Tomás Herrero', 'cue', 8],
    ];

    const HOLIDAYS = {
        '2026-10-12': 'Fiesta Nacional',
        '2026-11-02': 'Todos los Santos (traslado)',
        '2026-12-07': 'Constitución (traslado)',
        '2026-12-08': 'Inmaculada',
        '2026-12-25': 'Navidad',
        '2027-01-01': 'Año Nuevo',
        '2027-01-06': 'Reyes',
        '2027-03-25': 'Jueves Santo',
        '2027-03-26': 'Viernes Santo',
        '2027-05-03': 'Día de la Comunidad',
        '2027-08-16': 'Asunción (traslado)',
    };

    // Ausencias aprobadas: [persona, desde, hasta, tipo]. El tipo solo lo ve quien puede (D-088).
    const ABSENCES = [
        ['ana', '2026-12-21', '2027-01-01', 'Vacaciones'],
        ['javier', '2026-11-16', '2026-11-20', 'Vacaciones'],
        ['raul', '2026-12-28', '2027-01-08', 'Vacaciones'],
        ['cristina', '2026-10-19', '2026-10-23', 'Vacaciones'],
        ['irene', '2026-11-03', '2026-11-06', 'Vacaciones'],
        ['elena', '2027-02-01', '2027-05-28', 'Ausencia'],
        ['diego', '2026-12-21', '2026-12-31', 'Vacaciones'],
        ['luis', '2026-10-26', '2026-10-27', 'Asuntos propios'],
        ['sergio', '2027-01-04', '2027-01-08', 'Vacaciones'],
    ];
    // Agosto: cada cual dos o tres semanas.
    const AUGUST = [
        ['2027-08-02', '2027-08-20'],
        ['2027-08-09', '2027-08-27'],
        ['2027-07-26', '2027-08-13'],
    ];
    PEOPLE_RAW.forEach(([id], i) => {
        const [from, to] = AUGUST[i % 3];
        ABSENCES.push([id, from, to, 'Vacaciones']);
    });

    /*
     * Proyectos. kind: real | seguro | posible.
     * Asignaciones: [quién, modo, horas, desde, hasta]
     *   quién: id de persona o «hueco:<dep>»;
     *   modo: sem (h/semana) · total (h entre fechas) · mes (h/mes) · pct (% de la jornada).
     */
    const PROJECTS = [
        {
            id: 'kiwi2', code: 'KIWI-APP2', name: 'Kiwi · App fase 2', client: 'Kiwi Seguros', kind: 'real',
            from: '2026-09-01', to: '2026-11-27',
            allocs: [
                ['ana', 'sem', 16, '2026-09-01', '2026-11-27'],
                ['luis', 'sem', 24, '2026-09-01', '2026-11-27'],
                ['raul', 'sem', 32, '2026-09-01', '2026-11-27'],
                ['clara', 'sem', 24, '2026-09-01', '2026-11-27'],
                ['jorge', 'sem', 8, '2026-09-01', '2026-11-27'],
            ],
        },
        {
            id: 'acme', code: 'ACME-B2B', name: 'ACME · Ecommerce B2B', client: 'ACME Industrial', kind: 'real',
            from: '2026-10-05', to: '2027-03-26',
            allocs: [
                ['marta', 'sem', 32, '2026-10-05', '2027-03-26'],
                ['diego', 'sem', 12, '2026-10-05', '2027-03-26'],
                ['andres', 'sem', 36, '2026-10-05', '2027-03-26'],
                ['sergio', 'sem', 32, '2026-10-05', '2026-12-18'],
                ['laura', 'sem', 16, '2026-11-23', '2027-03-26'],
                ['beatriz', 'sem', 12, '2026-10-05', '2027-03-26'],
                ['hueco:dev', 'sem', 24, '2027-01-11', '2027-03-26'],
            ],
        },
        {
            id: 'sol', code: 'SOL-DS', name: 'Banco Sol · Sistema de diseño', client: 'Banco Sol', kind: 'real',
            from: '2026-09-14', to: '2027-02-26',
            allocs: [
                ['pablo', 'sem', 32, '2026-09-14', '2027-02-26'],
                ['irene', 'sem', 24, '2026-09-14', '2027-02-26'],
                ['hugo', 'sem', 12, '2026-09-14', '2027-02-26'],
                ['silvia', 'sem', 8, '2026-09-14', '2027-02-26'],
                ['carlos', 'sem', 16, '2026-10-05', '2026-10-30'],
            ],
        },
        {
            id: 'norte', code: 'NORTE-WEB', name: 'Viajes Norte · Rediseño web', client: 'Viajes Norte', kind: 'real',
            from: '2026-11-02', to: '2027-01-29',
            allocs: [
                ['carlos', 'sem', 32, '2026-11-02', '2027-01-29'],
                ['lucia', 'sem', 24, '2026-11-02', '2027-01-29'],
                ['luis', 'sem', 8, '2026-11-02', '2026-11-27'],
                ['ivan', 'sem', 32, '2026-11-16', '2027-01-29'],
                ['rocio', 'sem', 8, '2026-11-02', '2027-01-29'],
                ['tomas', 'sem', 8, '2026-11-02', '2027-01-29'],
            ],
        },
        {
            id: 'arbe', code: 'ARBE-APP', name: 'Clínica Arbe · App de citas', client: 'Clínica Arbe', kind: 'real',
            from: '2026-06-08', to: '2026-11-13',
            allocs: [
                ['javier', 'sem', 16, '2026-06-08', '2026-11-13'],
                ['clara', 'sem', 8, '2026-06-08', '2026-11-13'],
                ['alba', 'sem', 10, '2026-06-08', '2026-11-13'],
                ['jorge', 'sem', 3, '2026-06-08', '2026-11-13'],
            ],
        },
        {
            id: 'lince', code: 'LINCE-FEE', name: 'Grupo Lince · Fee de marketing', client: 'Grupo Lince', kind: 'real',
            from: '2026-01-01', to: '2027-12-31',
            allocs: [
                ['paula', 'sem', 12, '2026-01-01', '2027-12-31'],
                ['alvaro', 'sem', 24, '2026-01-01', '2027-12-31'],
                ['cristina', 'sem', 20, '2026-01-01', '2027-12-31'],
                ['daniel', 'sem', 16, '2026-01-01', '2027-12-31'],
                ['tomas', 'sem', 8, '2026-01-01', '2027-12-31'],
                ['rocio', 'sem', 16, '2026-01-01', '2027-12-31'],
                ['noelia', 'sem', 8, '2026-01-01', '2027-12-31'],
            ],
        },
        {
            id: 'valdemar', code: 'VALDEMAR', name: 'Ayto. de Valdemar · Sede electrónica', client: 'Ayuntamiento de Valdemar', kind: 'real',
            from: '2026-10-05', to: '2027-04-30',
            allocs: [
                ['elena', 'sem', 28, '2026-10-05', '2027-01-29'],
                ['hugo', 'sem', 20, '2026-10-05', '2027-04-30'],
                ['ivan', 'sem', 6, '2026-10-05', '2027-04-30'],
                ['silvia', 'sem', 6, '2026-10-05', '2027-04-30'],
                ['javier', 'sem', 16, '2026-10-05', '2027-04-30'],
                ['sara', 'sem', 18, '2026-11-23', '2027-04-30'],
                ['hueco:dis', 'sem', 16, '2027-02-01', '2027-04-30'],
            ],
        },
        {
            id: 'pagalo', code: 'PAGALO', name: 'Pagalo · Onboarding', client: 'Pagalo', kind: 'real',
            from: '2026-10-05', to: '2026-11-20',
            allocs: [
                ['nuria', 'sem', 32, '2026-10-05', '2026-11-20'],
                ['sara', 'sem', 24, '2026-10-05', '2026-11-20'],
                ['laura', 'sem', 16, '2026-10-05', '2026-11-20'],
                ['beatriz', 'sem', 6, '2026-10-05', '2026-11-20'],
                ['lucia', 'sem', 16, '2026-10-05', '2026-11-20'],
            ],
        },
        {
            id: 'faro', code: 'FARO', name: 'Editorial Faro · Plataforma de lectura', client: 'Editorial Faro', kind: 'real',
            from: '2026-10-05', to: '2027-06-25',
            allocs: [
                ['ana', 'sem', 12, '2026-10-05', '2027-06-25'],
                ['irene', 'sem', 12, '2026-10-05', '2026-12-18'],
                ['lucia', 'sem', 12, '2026-10-05', '2026-10-30'],
                ['sergio', 'sem', 8, '2026-10-05', '2027-06-25'],
                ['diego', 'sem', 8, '2026-10-05', '2027-06-25'],
                ['tomas', 'sem', 6, '2026-10-05', '2027-06-25'],
                ['nuria', 'sem', 24, '2026-11-23', '2027-06-25'],
            ],
        },
        {
            id: 'bodegas', code: 'BODEGAS', name: 'Bodegas Ruiz · Tienda online', client: 'Bodegas Ruiz', kind: 'real',
            from: '2027-01-11', to: '2027-04-16',
            allocs: [
                ['lucia', 'sem', 16, '2027-02-01', '2027-04-16'],
                ['sergio', 'sem', 28, '2027-01-11', '2027-04-16'],
                ['noelia', 'sem', 12, '2027-01-11', '2027-04-16'],
                ['jorge', 'sem', 6, '2027-01-11', '2027-04-16'],
            ],
        },
        {
            id: 'kiwim', code: 'KIWI-MANT', name: 'Kiwi · Mantenimiento', client: 'Kiwi Seguros', kind: 'real',
            from: '2026-01-01', to: '2027-12-31',
            allocs: [
                ['laura', 'mes', 30, '2026-01-01', '2027-12-31'],
                ['raul', 'mes', 10, '2026-01-01', '2027-12-31'],
            ],
        },
        {
            id: 'audax', code: 'AUDAX-WEB', name: 'Audax · Web propia', client: 'Audax Studio (interno)', kind: 'real',
            from: '2026-10-05', to: '2026-12-18',
            allocs: [
                ['nuria', 'sem', 6, '2026-10-05', '2026-12-18'],
                ['noelia', 'sem', 12, '2026-10-05', '2026-12-18'],
                ['daniel', 'sem', 8, '2026-10-05', '2026-12-18'],
                ['marta', 'sem', 4, '2026-10-05', '2026-12-18'],
                ['paula', 'sem', 8, '2026-10-05', '2026-12-18'],
            ],
        },
        // ── Previstos ──────────────────────────────────────────────────────────────
        {
            id: 'kiwi3', code: null, name: 'Kiwi · App fase 3', client: 'Kiwi Seguros', kind: 'seguro',
            from: '2026-11-30', to: '2027-04-30',
            allocs: [
                ['ana', 'sem', 20, '2026-11-30', '2027-04-30'],
                ['luis', 'sem', 24, '2026-11-30', '2027-04-30'],
                ['raul', 'sem', 32, '2026-11-30', '2027-04-30'],
                ['clara', 'sem', 24, '2026-12-07', '2027-04-30'],
                ['hueco:dev', 'sem', 40, '2027-02-01', '2027-04-30'],
                ['jorge', 'sem', 8, '2026-11-30', '2027-04-30'],
            ],
        },
        {
            id: 'acmep', code: null, name: 'ACME · Campaña de primavera', client: 'ACME Industrial', kind: 'seguro',
            from: '2027-01-11', to: '2027-03-26',
            allocs: [
                ['paula', 'sem', 8, '2027-01-11', '2027-03-26'],
                ['alvaro', 'sem', 12, '2027-01-11', '2027-03-26'],
                ['cristina', 'sem', 16, '2027-01-11', '2027-03-26'],
                ['rocio', 'sem', 20, '2027-01-11', '2027-03-26'],
                ['hueco:dis', 'sem', 12, '2027-01-11', '2027-02-26'],
                ['beatriz', 'sem', 4, '2027-01-11', '2027-03-26'],
            ],
        },
        {
            id: 'marazul', code: null, name: 'Hotel Mar Azul · Web y branding', client: 'Hotel Mar Azul', newClient: true, kind: 'posible',
            from: '2026-11-03', to: '2026-12-18',
            allocs: [
                ['hueco:dis', 'total', 80, '2026-11-03', '2026-11-27'],
                ['luis', 'pct', 50, '2026-11-09', '2026-12-18'],
                ['hueco:dev', 'total', 120, '2026-11-30', '2026-12-18'],
                ['silvia', 'sem', 6, '2026-11-03', '2026-12-18'],
            ],
        },
        {
            id: 'coop', code: null, name: 'Cooperativa Agraria del Sur · Portal de socios', client: 'Cooperativa Agraria del Sur', newClient: true, kind: 'posible',
            from: '2027-02-01', to: '2027-06-25',
            allocs: [
                ['hueco:dis', 'sem', 32, '2027-02-01', '2027-03-26'],
                ['pablo', 'sem', 16, '2027-03-01', '2027-06-25'],
                ['hueco:dev', 'sem', 64, '2027-03-01', '2027-06-25'],
                ['tomas', 'sem', 6, '2027-02-01', '2027-06-25'],
            ],
        },
        {
            id: 'museo', code: null, name: 'Museo del Tranvía · Exposición digital', client: 'Museo del Tranvía', kind: 'posible',
            from: '2026-11-16', to: '2027-01-29',
            allocs: [
                ['irene', 'sem', 16, '2026-11-16', '2027-01-29'],
                ['carlos', 'sem', 12, '2026-11-16', '2027-01-29'],
                ['hueco:mkt', 'sem', 16, '2026-11-16', '2027-01-29'],
                ['hugo', 'sem', 16, '2026-11-16', '2027-01-29'],
            ],
        },
    ];

    // ── Fechas ──────────────────────────────────────────────────────────────────
    const DAY = 86400000;
    const toMs = (iso) => {
        const [y, m, d] = iso.split('-').map(Number);
        return Date.UTC(y, m - 1, d);
    };
    const toIso = (ms) => new Date(ms).toISOString().slice(0, 10);
    const addDays = (iso, n) => toIso(toMs(iso) + n * DAY);
    const weekday = (iso) => (new Date(toMs(iso)).getUTCDay() + 6) % 7; // 0 = lunes
    const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sept', 'oct', 'nov', 'dic'];
    const MONTHS_LONG = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    const dayMonth = (iso) => {
        const d = new Date(toMs(iso));
        return `${d.getUTCDate()} ${MONTHS[d.getUTCMonth()]}`;
    };
    // Semana ISO
    const isoWeek = (iso) => {
        const d = new Date(toMs(iso));
        const day = (d.getUTCDay() + 6) % 7;
        d.setUTCDate(d.getUTCDate() - day + 3);
        const firstThursday = new Date(Date.UTC(d.getUTCFullYear(), 0, 4));
        return 1 + Math.round(((d - firstThursday) / DAY - 3 + ((firstThursday.getUTCDay() + 6) % 7)) / 7);
    };

    const DAYS = Array.from({ length: DAYS_IN_HORIZON }, (_, i) => addDays(START, i));

    // ── Personas, capacidad diaria ──────────────────────────────────────────────
    const PEOPLE = PEOPLE_RAW.map(([id, name, dept, hpd]) => ({ id, name, dept, hpd }));
    const personById = Object.fromEntries(PEOPLE.map((p) => [p.id, p]));
    const absenceOn = {}; // persona → fecha → tipo
    for (const [pid, from, to, type] of ABSENCES) {
        absenceOn[pid] ??= {};
        for (let d = from; d <= to; d = addDays(d, 1)) absenceOn[pid][d] = type;
    }
    const isWeekday = (d) => weekday(d) < 5;
    const isWorkingDept = (d) => isWeekday(d) && !HOLIDAYS[d];
    const capOn = (pid, d) => {
        if (!isWorkingDept(d)) return 0;
        if (absenceOn[pid]?.[d]) return 0;
        return personById[pid].hpd;
    };

    // ── Reparto de asignaciones por día ─────────────────────────────────────────
    // load[rowKey][date] = [{p: proyecto, h}]
    const load = {};
    const push = (row, d, p, h) => {
        if (h <= 0) return;
        load[row] ??= {};
        (load[row][d] ??= []).push({ p, h });
    };
    const workingDaysFor = (who, from, to) => {
        const out = [];
        for (let d = from; d <= to; d = addDays(d, 1)) {
            const ok = who.startsWith('hueco:') ? isWorkingDept(d) : capOn(who, d) > 0;
            if (ok) out.push(d);
        }
        return out;
    };
    for (const project of PROJECTS) {
        for (const [who, mode, h, from, to] of project.allocs) {
            const days = workingDaysFor(who, from, to);
            if (mode === 'sem') days.forEach((d) => push(who, d, project.id, h / 5));
            if (mode === 'pct') days.forEach((d) => push(who, d, project.id, (personById[who].hpd * h) / 100));
            if (mode === 'total') days.forEach((d) => push(who, d, project.id, h / days.length));
            if (mode === 'mes') {
                const byMonth = {};
                days.forEach((d) => (byMonth[d.slice(0, 7)] ??= []).push(d));
                Object.values(byMonth).forEach((ds) => ds.forEach((d) => push(who, d, project.id, h / ds.length)));
            }
        }
    }

    const projectById = Object.fromEntries(PROJECTS.map((p) => [p.id, p]));

    // ── Periodos ────────────────────────────────────────────────────────────────
    function periods(unit, count) {
        const out = [];
        if (unit === 'week') {
            for (let i = 0; i < count; i++) {
                const from = addDays(START, i * 7);
                const to = addDays(from, 6);
                out.push({
                    key: from, from, to, unit,
                    label: `S${isoWeek(from)}`,
                    sub: dayMonth(from),
                    long: `semana ${isoWeek(from)} (${dayMonth(from)} – ${dayMonth(addDays(from, 4))})`,
                    monthStart: i === 0 || from.slice(0, 7) !== addDays(from, -7).slice(0, 7) ? MONTHS[Number(from.slice(5, 7)) - 1] : null,
                    current: from <= TODAY && TODAY <= to,
                });
            }
        } else {
            let y = 2026, m = 9; // octubre (0-based)
            for (let i = 0; i < count; i++) {
                const from = `${y}-${String(m + 1).padStart(2, '0')}-01`;
                const last = new Date(Date.UTC(y, m + 1, 0)).getUTCDate();
                const to = `${y}-${String(m + 1).padStart(2, '0')}-${last}`;
                out.push({
                    key: from, from: i === 0 ? START : from, to, unit,
                    label: MONTHS[m], sub: String(y), long: `${MONTHS_LONG[m]} de ${y}`,
                    monthStart: null, current: i === 0,
                });
                m++;
                if (m === 12) { m = 0; y++; }
            }
        }
        return out;
    }

    /** Agrega una fila (persona o hueco) en un periodo. */
    function cell(rowKey, period) {
        const isGap = rowKey.startsWith('hueco:');
        const res = { cap: 0, real: 0, seguro: 0, posible: 0, items: {}, absent: 0, holidays: 0, absenceType: null, workdays: 0 };
        for (let d = period.from; d <= period.to; d = addDays(d, 1)) {
            if (d < START) continue;
            if (isWeekday(d)) {
                if (HOLIDAYS[d]) res.holidays++;
                else if (!isGap && absenceOn[rowKey]?.[d]) { res.absent++; res.absenceType = absenceOn[rowKey][d]; }
                else res.workdays++;
            }
            if (!isGap) res.cap += capOn(rowKey, d);
            for (const { p, h } of load[rowKey]?.[d] ?? []) {
                const kind = projectById[p].kind;
                res[kind] += h;
                res.items[p] = (res.items[p] ?? 0) + h;
            }
        }
        res.items = Object.entries(res.items)
            .map(([p, h]) => ({ project: projectById[p], h }))
            .sort((a, b) => ['real', 'seguro', 'posible'].indexOf(a.project.kind) - ['real', 'seguro', 'posible'].indexOf(b.project.kind) || b.h - a.h);
        return res;
    }

    function deptCell(deptId, period) {
        const res = { cap: 0, real: 0, seguro: 0, posible: 0, gapH: 0, items: {} };
        const rows = [...PEOPLE.filter((p) => p.dept === deptId).map((p) => p.id), `hueco:${deptId}`];
        for (const r of rows) {
            const c = cell(r, period);
            res.cap += c.cap;
            res.real += c.real; res.seguro += c.seguro; res.posible += c.posible;
            if (r.startsWith('hueco:')) res.gapH += c.real + c.seguro + c.posible;
            for (const it of c.items) res.items[it.project.id] = (res.items[it.project.id] ?? 0) + it.h;
        }
        res.items = Object.entries(res.items)
            .map(([p, h]) => ({ project: projectById[p], h }))
            .sort((a, b) => ['real', 'seguro', 'posible'].indexOf(a.project.kind) - ['real', 'seguro', 'posible'].indexOf(b.project.kind) || b.h - a.h);
        return res;
    }

    const gapsOf = (deptId) => PROJECTS.flatMap((p) => p.allocs.filter((a) => a[0] === `hueco:${deptId}`).map((a) => ({ project: p, alloc: a })));

    return {
        TODAY, START, DEPTS, PEOPLE, PROJECTS, HOLIDAYS, MONTHS, MONTHS_LONG,
        personById, projectById, periods, cell, deptCell, gapsOf, addDays, toMs, dayMonth, weekday, isoWeek, capOn, load,
    };
})();

if (typeof module !== 'undefined') module.exports = PV;
