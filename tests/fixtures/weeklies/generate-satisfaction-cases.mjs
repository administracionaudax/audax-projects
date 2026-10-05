/**
 * Genera tests/fixtures/weeklies/satisfaction-cases.json ejecutando el ORIGINAL de WeeklySync
 * (`supabase/functions/_shared/satisfaction.js`) con node. El port a PHP
 * (App\Domain\Weeklies\SatisfactionStabilizer) debe dar exactamente lo mismo
 * (tests/Unit/Weeklies/SatisfactionStabilizerTest.php).
 *
 * Uso (solo hace falta volver a generarlo si cambian los casos):
 *   node tests/fixtures/weeklies/generate-satisfaction-cases.mjs <ruta>/supabase/functions/_shared/satisfaction.js
 *
 * En las entradas, una clave ausente es `undefined` en JS (JSON no tiene undefined).
 */
import { copyFileSync, mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const source = process.argv[2];
if (!source) {
    console.error('Falta la ruta de satisfaction.js');
    process.exit(1);
}

// Copia a .mjs para importarlo como módulo ES sin depender del package.json del repo original.
const copy = join(
    mkdtempSync(join(tmpdir(), 'satisfaction-')),
    'satisfaction.mjs',
);
copyFileSync(source, copy);
const js = await import(pathToFileURL(copy).href);

const texts = {
    empty: '',
    spaces: '   \n\t  ',
    short: 'Todo bien esta semana con el cliente',
    routine:
        'Seguimos con el mantenimiento y el soporte habitual, reunión de seguimiento semanal con el equipo y revisión de tareas sin cambios relevantes',
    delivered:
        'Esta semana hemos entregado la nueva web y el cliente dio el ok, se ha publicado la landing y quedó muy satisfecho con el resultado final del proyecto',
    severe: 'El cliente está molesto porque hay un bloqueo total en producción, error crítico en la pasarela de pagos y retraso en la entrega comprometida para hoy',
    mixed: 'Avance en el diseño pero hay un problema con el retraso de contenidos, aunque completamos la mejora del rendimiento y queda entregado el informe mensual',
    neutral:
        'Hemos trabajado en varias tareas del proyecto durante toda la semana con normalidad y en coordinación con el equipo del cliente en general',
    shouting:
        'EL CLIENTE MOLESTO POR LA INCIDENCIA GRAVE EN EL SERVIDOR, ESCALÓ EL PROBLEMA A DIRECCIÓN Y PIDE UNA SOLUCIÓN INMEDIATA HOY MISMO SIN FALTA',
    unicodeSpaces:
        'Hito completado y objetivo cumplido:\tel cliente agradeció\nla entrega　y el lanzado de la campaña fue un éxito total esta semana ',
    positiveTone:
        'Gran avance y progreso, mejora clara, logro del hito, éxito en la entrega exitosa, todo fluido y sin bloqueos durante la semana con el cliente',
    negativeTone:
        'Problema tras problema: retraso, incidencia, riesgo, fallo y error en producción, con demora y atasco general del proyecto esta semana',
    routineSevere:
        'Seguimos con soporte y mantenimiento, pero el servidor quedó parado varias horas por un fallo y la tienda estuvo sin vender durante la tarde',
};

const deltas = [
    0,
    1,
    3,
    -3,
    6,
    -6,
    8,
    -8,
    12,
    -12,
    0.4,
    -0.4,
    2.5,
    -2.5,
    1.5,
    '3',
    ' -5 ',
    'abc',
    null,
    'Infinity',
    '0x5',
    '',
    true,
];
const evidence = [
    'HIGH',
    'medium',
    ' low ',
    'NONE',
    '',
    null,
    undefined,
    5,
    'High',
];
const impacts = [true, false, 'yes', '', 0, null, undefined, '0', 1];
const confidences = [
    0.9,
    0.6,
    0.4,
    null,
    undefined,
    '0.55',
    'abc',
    2,
    -1,
    0.65,
    0.5,
];
const satisfactions = [
    50,
    95,
    85,
    5,
    15,
    92,
    82,
    8,
    18,
    100,
    0,
    null,
    undefined,
    '90',
];

/** Quita las claves undefined (en JSON, ausentes). */
const clean = (input) =>
    Object.fromEntries(
        Object.entries(input).filter(([, value]) => value !== undefined),
    );

const cases = [];
const textKeys = Object.keys(texts);
let n = 0;

for (const textKey of textKeys) {
    for (const requestedDelta of deltas) {
        // Parámetros rotados de forma determinista para cubrir combinaciones sin explotar el tamaño.
        const input = {
            requestedDelta,
            currentSatisfaction: satisfactions[n % satisfactions.length],
            reportText: texts[textKey],
            evidenceLevel: evidence[(n * 7) % evidence.length],
            explicitClientImpact: impacts[(n * 5) % impacts.length],
            confidence: confidences[(n * 3) % confidences.length],
        };
        n++;
        cases.push({
            name: `${textKey} / ${JSON.stringify(requestedDelta)} / ${n}`,
            input,
        });
    }
}

// Casos fijos de cada regla y de los topes.
const fixed = [
    [
        'acepta con evidencia alta e impacto',
        {
            requestedDelta: 5,
            currentSatisfaction: 50,
            reportText: texts.delivered,
            evidenceLevel: 'HIGH',
            explicitClientImpact: true,
            confidence: 0.9,
        },
    ],
    [
        'sube hasta 8 con evidencia alta',
        {
            requestedDelta: 8,
            currentSatisfaction: 50,
            reportText: texts.delivered,
            evidenceLevel: 'HIGH',
            explicitClientImpact: true,
            confidence: 0.9,
        },
    ],
    [
        'frena la subida desde 92',
        {
            requestedDelta: 8,
            currentSatisfaction: 92,
            reportText: texts.delivered,
            evidenceLevel: 'HIGH',
            explicitClientImpact: true,
            confidence: 0.9,
        },
    ],
    [
        'frena la subida desde 82',
        {
            requestedDelta: 8,
            currentSatisfaction: 82,
            reportText: texts.delivered,
            evidenceLevel: 'HIGH',
            explicitClientImpact: true,
            confidence: 0.9,
        },
    ],
    [
        'frena la bajada hasta 8',
        {
            requestedDelta: -8,
            currentSatisfaction: 8,
            reportText: texts.severe,
            evidenceLevel: 'HIGH',
            explicitClientImpact: true,
            confidence: 0.9,
        },
    ],
    [
        'frena la bajada hasta 18',
        {
            requestedDelta: -8,
            currentSatisfaction: 18,
            reportText: texts.severe,
            evidenceLevel: 'HIGH',
            explicitClientImpact: true,
            confidence: 0.9,
        },
    ],
    [
        'baja con evidencia media',
        {
            requestedDelta: -6,
            currentSatisfaction: 60,
            reportText: texts.severe,
            evidenceLevel: 'MEDIUM',
            explicitClientImpact: false,
            confidence: 0.7,
        },
    ],
    [
        'confianza baja resta 2',
        {
            requestedDelta: 4,
            currentSatisfaction: 50,
            reportText: texts.delivered,
            evidenceLevel: 'MEDIUM',
            explicitClientImpact: true,
            confidence: 0.3,
        },
    ],
    [
        'confianza media resta 1',
        {
            requestedDelta: 4,
            currentSatisfaction: 50,
            reportText: texts.delivered,
            evidenceLevel: 'LOW',
            explicitClientImpact: true,
            confidence: 0.6,
        },
    ],
    [
        'tono positivo invierte una bajada',
        {
            requestedDelta: -4,
            currentSatisfaction: 50,
            reportText: texts.positiveTone,
            evidenceLevel: 'LOW',
            explicitClientImpact: true,
            confidence: 0.9,
        },
    ],
    [
        'tono negativo invierte una subida',
        {
            requestedDelta: 4,
            currentSatisfaction: 50,
            reportText: texts.negativeTone,
            evidenceLevel: 'LOW',
            explicitClientImpact: true,
            confidence: 0.9,
        },
    ],
    [
        'semana de rutina',
        {
            requestedDelta: 3,
            currentSatisfaction: 50,
            reportText: texts.routine,
            evidenceLevel: 'HIGH',
            explicitClientImpact: true,
            confidence: 0.9,
        },
    ],
    [
        'rutina con algo grave',
        {
            requestedDelta: -3,
            currentSatisfaction: 50,
            reportText: texts.routineSevere,
            evidenceLevel: 'HIGH',
            explicitClientImpact: true,
            confidence: 0.9,
        },
    ],
    [
        'sin impacto explícito',
        {
            requestedDelta: 3,
            currentSatisfaction: 50,
            reportText: texts.neutral,
            evidenceLevel: 'HIGH',
            explicitClientImpact: false,
            confidence: 0.9,
        },
    ],
    [
        'poco texto',
        {
            requestedDelta: 3,
            currentSatisfaction: 50,
            reportText: texts.short,
            evidenceLevel: 'HIGH',
            explicitClientImpact: true,
            confidence: 0.9,
        },
    ],
    [
        'sin texto',
        {
            requestedDelta: 3,
            currentSatisfaction: 50,
            reportText: null,
            evidenceLevel: 'HIGH',
            explicitClientImpact: true,
            confidence: 0.9,
        },
    ],
    ['todo ausente', {}],
    ['solo delta', { requestedDelta: 3 }],
    [
        'delta en array',
        {
            requestedDelta: [4],
            currentSatisfaction: 50,
            reportText: texts.delivered,
            evidenceLevel: 'HIGH',
            explicitClientImpact: true,
            confidence: 0.9,
        },
    ],
    [
        'delta en array vacío',
        {
            requestedDelta: [],
            currentSatisfaction: 50,
            reportText: texts.delivered,
            evidenceLevel: 'HIGH',
            explicitClientImpact: true,
        },
    ],
    [
        'satisfacción nula al bajar',
        {
            requestedDelta: -5,
            currentSatisfaction: null,
            reportText: texts.severe,
            evidenceLevel: 'HIGH',
            explicitClientImpact: true,
            confidence: 0.9,
        },
    ],
    [
        'satisfacción ausente al bajar',
        {
            requestedDelta: -5,
            reportText: texts.severe,
            evidenceLevel: 'HIGH',
            explicitClientImpact: true,
            confidence: 0.9,
        },
    ],
    [
        'confianza nula cuenta como 0',
        {
            requestedDelta: 4,
            currentSatisfaction: 50,
            reportText: texts.delivered,
            evidenceLevel: 'HIGH',
            explicitClientImpact: true,
            confidence: null,
        },
    ],
    [
        'confianza ausente no cuenta',
        {
            requestedDelta: 4,
            currentSatisfaction: 50,
            reportText: texts.delivered,
            evidenceLevel: 'HIGH',
            explicitClientImpact: true,
        },
    ],
    [
        'mayúsculas y acentos',
        {
            requestedDelta: -7,
            currentSatisfaction: 70,
            reportText: texts.shouting,
            evidenceLevel: 'high',
            explicitClientImpact: 'sí',
            confidence: '0.8',
        },
    ],
    [
        'espacios unicode',
        {
            requestedDelta: 7,
            currentSatisfaction: 40,
            reportText: texts.unicodeSpaces,
            evidenceLevel: ' HIGH ',
            explicitClientImpact: true,
            confidence: 1,
        },
    ],
];

for (const [name, input] of fixed) {
    cases.push({ name, input });
}

const output = {
    source: 'ws:supabase/functions/_shared/satisfaction.js (WeeklySync b8f6f63)',
    note: 'Generado por generate-satisfaction-cases.mjs. Una clave ausente en input es undefined en JS.',
    stabilize: cases.map(({ name, input }) => ({
        name,
        input: clean(input),
        expected: js.stabilizeSatisfactionDelta(input),
    })),
    reasoning: [
        ['Mejora clara', 0, 'guarded-to-stable'],
        ['', 0, 'too-little-information'],
        [null, 0, 'zero-or-invalid-request'],
        ['  Entrega validada  ', 3, 'dampened'],
        ['Entrega validada', 3, 'accepted'],
        ['', 3, 'dampened'],
        [null, -2, 'dampened'],
        ['Incidencia grave', -2, 'dampened'],
    ].map(([base, delta, rule]) => ({
        input: [base, delta, rule],
        expected: js.getStableReasoning(base, delta, rule),
    })),
    cleanJson: [
        '```json\n{"a":1}\n```',
        '```\n{"a":1}\n```',
        '  {"a":1}  ',
        '',
        null,
        '```json{"a":1}```',
        'texto ```json dentro```',
    ].map((value) => ({ input: value, expected: js.cleanJsonResponse(value) })),
    tone: Object.values(texts).map((text) => ({
        input: text,
        expected: js.getToneScore(text),
    })),
    evidence: [
        'HIGH',
        'medium',
        ' Low ',
        'none',
        '',
        null,
        0,
        5,
        'HIGHEST',
    ].map((value) => ({
        input: value,
        expected: js.normalizeEvidenceLevel(value),
    })),
    confidence: [
        0.5,
        '0.7',
        2,
        -3,
        null,
        'abc',
        '',
        true,
        ' 0.25 ',
        'Infinity',
    ].map((value) => ({
        input: value,
        expected: js.normalizeConfidence(value),
    })),
};

const target = join(
    dirname(fileURLToPath(import.meta.url)),
    'satisfaction-cases.json',
);
// Un caso por línea: legible en un diff y sin inflar el fichero.
const lines = Object.entries(output).map(([key, value]) =>
    Array.isArray(value)
        ? `  ${JSON.stringify(key)}: [\n${value.map((item) => `    ${JSON.stringify(item)}`).join(',\n')}\n  ]`
        : `  ${JSON.stringify(key)}: ${JSON.stringify(value)}`,
);
writeFileSync(target, `{\n${lines.join(',\n')}\n}\n`);
console.log(`${output.stabilize.length} casos → ${target}`);
