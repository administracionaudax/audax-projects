import { CircleCheck, CircleX, Minus } from 'lucide-react';
import type { CSSProperties } from 'react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import {
    sequentialColor,
    SEQUENTIAL_MIX,
} from '@/components/charts/chart-config';
import type { Rgba } from '@/components/styleguide/contrast';
import {
    AA_NON_TEXT,
    AA_TEXT,
    composite,
    contrastRatio,
    formatRatio,
    parseCssColor,
    readThemeDeclarations,
} from '@/components/styleguide/contrast';
import { cn } from '@/lib/utils';

/**
 * Tokens del tema en claro y oscuro a la vez. Los valores se leen de la hoja de estilos
 * (bloques :root y .dark) y el contraste se calcula con getComputedStyle en el navegador.
 */

type Role = 'text' | 'non-text' | 'surface' | 'decorative';

type TokenSpec = {
    token: string;
    label: string;
    role: Role;
    /** text / non-text: fondo sobre el que se mide. surface: texto que se mide encima. */
    against?: string;
};

type Group = { id: string; title: string; tokens: TokenSpec[] };

const GROUPS: Group[] = [
    {
        id: 'brand',
        title: 'Marca',
        tokens: [
            {
                token: 'brand',
                label: 'Azul Audax: marca, foco y barras',
                role: 'non-text',
                against: 'background',
            },
            {
                token: 'brand-navy',
                label: 'Navy de marca: fondos oscuros (texto blanco)',
                role: 'surface',
                against: 'primary-foreground',
            },
            {
                token: 'primary',
                label: 'Botón principal (texto blanco)',
                role: 'surface',
                against: 'primary-foreground',
            },
            {
                token: 'primary-text',
                label: 'Enlaces y botón secundario',
                role: 'text',
                against: 'background',
            },
            {
                token: 'ring',
                label: 'Anillo de foco',
                role: 'non-text',
                against: 'card',
            },
        ],
    },
    {
        id: 'surface',
        title: 'Superficies',
        tokens: [
            { token: 'background', label: 'Fondo general', role: 'surface' },
            { token: 'card', label: 'Tarjetas', role: 'surface' },
            { token: 'popover', label: 'Menús y tooltips', role: 'surface' },
            {
                token: 'muted',
                label: 'surface-muted: filas alternas, zonas secundarias',
                role: 'surface',
            },
            { token: 'accent', label: 'Selección y hover', role: 'surface' },
            { token: 'sidebar', label: 'Barra lateral', role: 'surface' },
            {
                token: 'border',
                label: 'Bordes y separadores',
                role: 'decorative',
            },
            {
                token: 'input',
                label: 'Borde de campos',
                role: 'non-text',
                against: 'card',
            },
        ],
    },
    {
        id: 'text',
        title: 'Texto',
        tokens: [
            {
                token: 'foreground',
                label: 'Texto principal',
                role: 'text',
                against: 'background',
            },
            {
                token: 'muted-foreground',
                label: 'Texto secundario (sobre surface-muted)',
                role: 'text',
                against: 'muted',
            },
            {
                token: 'primary-text',
                label: 'Texto azul sobre selección',
                role: 'text',
                against: 'accent',
            },
            {
                token: 'destructive-foreground',
                label: 'Texto de error',
                role: 'text',
                against: 'card',
            },
        ],
    },
    {
        id: 'status',
        title: 'Estados',
        tokens: [
            {
                token: 'success',
                label: 'Correcto',
                role: 'text',
                against: 'card',
            },
            { token: 'warning', label: 'Aviso', role: 'text', against: 'card' },
            {
                token: 'danger',
                label: 'Error, exceso',
                role: 'text',
                against: 'card',
            },
            {
                token: 'info',
                label: 'Información',
                role: 'text',
                against: 'card',
            },
            {
                token: 'destructive',
                label: 'Botón destructivo (texto blanco)',
                role: 'surface',
                against: 'primary-foreground',
            },
            { token: 'success-soft', label: 'Fondo correcto', role: 'surface' },
            { token: 'warning-soft', label: 'Fondo aviso', role: 'surface' },
            { token: 'danger-soft', label: 'Fondo error', role: 'surface' },
            { token: 'info-soft', label: 'Fondo información', role: 'surface' },
        ],
    },
    {
        id: 'load',
        title: 'Carga (semáforo)',
        tokens: [
            {
                token: 'muted-foreground',
                label: 'Sin capacidad: icono sobre gris',
                role: 'non-text',
                against: 'neutral-soft',
            },
            {
                token: 'info',
                label: '< 70 %: icono sobre azul suave',
                role: 'non-text',
                against: 'info-soft',
            },
            {
                token: 'success',
                label: '70–100 %: icono sobre verde suave',
                role: 'non-text',
                against: 'success-soft',
            },
            {
                token: 'warning',
                label: '100–120 %: icono sobre ámbar suave',
                role: 'non-text',
                against: 'warning-soft',
            },
            {
                token: 'danger',
                label: '> 120 %: icono sobre rojo suave',
                role: 'non-text',
                against: 'danger-soft',
            },
            {
                token: 'neutral-soft',
                label: 'Celda sin capacidad',
                role: 'surface',
                against: 'muted-foreground',
            },
        ],
    },
    {
        id: 'data',
        title: 'Datos (orden fijo, nunca se cicla)',
        tokens: [1, 2, 3, 4, 5, 6].map((n) => ({
            token: `chart-${n}`,
            label: `Serie ${n}`,
            role: 'non-text' as const,
            against: 'card',
        })),
    },
];

const PROBES = Array.from(
    new Set(
        GROUPS.flatMap((g) =>
            g.tokens.flatMap((t) => [t.token, t.against ?? '']),
        ).concat(['background', 'card', 'foreground']),
    ),
).filter(Boolean);

type Result = { ratio: number; threshold: number | null; over: string };

function evaluate(spec: TokenSpec, m: Record<string, Rgba>): Result | null {
    const white: Rgba = { r: 255, g: 255, b: 255, a: 1 };

    if (!m.background || !m.card) {
        return null;
    }

    const base = composite(m.background, white);
    const card = composite(m.card, base);
    const surface = (name: string): Rgba | null => {
        if (name === 'background') {
            return base;
        }

        if (name === 'card') {
            return card;
        }

        return m[name] ? composite(m[name], card) : null;
    };

    if (spec.role === 'surface') {
        const bg = surface(spec.token);
        const fgName = spec.against ?? 'foreground';
        const fg = bg && m[fgName] ? composite(m[fgName], bg) : null;

        return bg && fg
            ? {
                  ratio: contrastRatio(fg, bg),
                  threshold: AA_TEXT,
                  over: `texto ${fgName} encima`,
              }
            : null;
    }

    const overName = spec.against ?? 'background';
    const bg = surface(overName);
    const fg = bg && m[spec.token] ? composite(m[spec.token], bg) : null;

    if (!bg || !fg) {
        return null;
    }

    return {
        ratio: contrastRatio(fg, bg),
        threshold:
            spec.role === 'text'
                ? AA_TEXT
                : spec.role === 'non-text'
                  ? AA_NON_TEXT
                  : null,
        over: `sobre ${overName}`,
    };
}

function ThemePanel({
    theme,
    vars,
}: {
    theme: 'light' | 'dark';
    vars: Record<string, string>;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const [measured, setMeasured] = useState<Record<string, Rgba>>({});

    useLayoutEffect(() => {
        const panel = ref.current;

        if (!panel) {
            return;
        }

        const next: Record<string, Rgba> = {};

        panel.querySelectorAll<HTMLElement>('[data-probe]').forEach((probe) => {
            const color = parseCssColor(
                getComputedStyle(probe).backgroundColor,
            );

            if (color && probe.dataset.probe) {
                next[probe.dataset.probe] = color;
            }
        });

        setMeasured(next);
    }, [vars]);

    return (
        <div
            ref={ref}
            data-theme-panel={theme}
            className={cn(
                'min-w-0 rounded-md border bg-background p-4 text-foreground',
                theme === 'dark' && 'dark',
            )}
            style={vars as CSSProperties}
        >
            <h3 className="mb-4 text-lg">
                {theme === 'light' ? 'Tema claro' : 'Tema oscuro'}
            </h3>

            <div aria-hidden="true" className="hidden">
                {PROBES.map((name) => (
                    <span
                        key={name}
                        data-probe={name}
                        style={{ backgroundColor: `var(--${name})` }}
                    />
                ))}
            </div>

            <div className="grid gap-6">
                {GROUPS.map((group) => (
                    <div key={group.id}>
                        <h4 className="mb-2 text-sm font-medium text-muted-foreground">
                            {group.title}
                        </h4>
                        <ul className="grid gap-2">
                            {group.tokens.map((spec) => (
                                <TokenRow
                                    key={`${spec.token}-${spec.against ?? ''}-${spec.role}`}
                                    spec={spec}
                                    value={vars[`--${spec.token}`] ?? ''}
                                    result={evaluate(spec, measured)}
                                />
                            ))}
                        </ul>
                    </div>
                ))}

                <div>
                    <h4 className="mb-2 text-sm font-medium text-muted-foreground">
                        Rampa secuencial (heatmap) y degradado de marca
                    </h4>
                    <div className="flex flex-wrap items-center gap-1.5">
                        {[0, ...SEQUENTIAL_MIX.map((_, i) => i + 1)].map(
                            (step) => (
                                <span
                                    key={step}
                                    className="size-7 rounded-md border"
                                    style={{
                                        backgroundColor: sequentialColor(step),
                                    }}
                                    title={
                                        step === 0
                                            ? 'Sin datos'
                                            : `Paso ${step}`
                                    }
                                />
                            ),
                        )}
                    </div>
                    <div className="mt-3 flex h-14 items-center rounded-md px-4 text-sm text-white bg-brand-gradient">
                        bg-brand-gradient · solo login, portal y estados vacíos
                        grandes
                    </div>
                </div>
            </div>
        </div>
    );
}

function TokenRow({
    spec,
    value,
    result,
}: {
    spec: TokenSpec;
    value: string;
    result: Result | null;
}) {
    const surfaceName =
        spec.role === 'surface' ? spec.token : (spec.against ?? 'background');
    const inkName =
        spec.role === 'surface' ? (spec.against ?? 'foreground') : spec.token;

    return (
        <li className="grid grid-cols-[2.5rem_minmax(0,1fr)] items-center gap-x-3 gap-y-1 sm:grid-cols-[2.5rem_minmax(0,1fr)_auto]">
            <span
                aria-hidden="true"
                className="flex size-10 items-center justify-center rounded-md border text-sm"
                style={{ backgroundColor: `var(--${surfaceName})` }}
            >
                {spec.role === 'text' || spec.role === 'surface' ? (
                    <span style={{ color: `var(--${inkName})` }}>Aa</span>
                ) : (
                    <span
                        className="h-4 w-5 rounded-sm"
                        style={{ backgroundColor: `var(--${spec.token})` }}
                    />
                )}
            </span>
            <span className="min-w-0">
                <code className="block truncate text-sm">--{spec.token}</code>
                <span className="block text-xs text-muted-foreground">
                    {spec.label} · <span className="tabular">{value}</span>
                </span>
            </span>
            <span className="col-start-2 sm:col-start-auto">
                <ContrastChip result={result} />
            </span>
        </li>
    );
}

function ContrastChip({ result }: { result: Result | null }) {
    if (!result) {
        return (
            <span className="text-xs text-muted-foreground">Calculando…</span>
        );
    }

    const ratio = formatRatio(result.ratio);

    if (result.threshold === null) {
        return (
            <span className="inline-flex items-center gap-1 rounded-md bg-neutral-soft px-1.5 py-0.5 text-xs text-foreground">
                <Minus
                    aria-hidden="true"
                    className="size-3.5 text-muted-foreground"
                />
                <span className="tabular">{ratio}</span> · Decorativo
            </span>
        );
    }

    const pass = result.ratio >= result.threshold;
    const Icon = pass ? CircleCheck : CircleX;

    return (
        <span
            data-contrast={pass ? 'pass' : 'fail'}
            title={`${ratio} ${result.over}; mínimo AA ${formatRatio(result.threshold)}`}
            className={cn(
                'inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs text-foreground',
                pass ? 'bg-success-soft' : 'bg-danger-soft',
            )}
        >
            <Icon
                aria-hidden="true"
                className={cn(
                    'size-3.5',
                    pass ? 'text-success' : 'text-danger',
                )}
            />
            <span className="tabular">{ratio}</span>
            <span className="font-medium">
                {pass ? 'PASA AA' : 'NO PASA AA'}
            </span>
            <span className="text-muted-foreground">
                (≥ {result.threshold === AA_TEXT ? '4,5' : '3'})
            </span>
        </span>
    );
}

export function ColorTokens() {
    const [declarations, setDeclarations] = useState<{
        light: Record<string, string>;
        dark: Record<string, string>;
    } | null>(null);

    useEffect(() => {
        setDeclarations(readThemeDeclarations());
    }, []);

    if (declarations === null) {
        return (
            <p className="text-sm text-muted-foreground">Leyendo el tema…</p>
        );
    }

    if (!declarations.light['--background']) {
        return (
            <p className="text-sm text-danger">
                No se han podido leer los tokens del tema desde la hoja de
                estilos.
            </p>
        );
    }

    return (
        <div className="grid gap-4 xl:grid-cols-2">
            <ThemePanel theme="light" vars={declarations.light} />
            <ThemePanel theme="dark" vars={declarations.dark} />
        </div>
    );
}
