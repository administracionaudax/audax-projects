import type { LucideIcon } from 'lucide-react';
import { ChevronDown, Monitor, Moon, Sun } from 'lucide-react';
import type { ReactNode } from 'react';
import { AudaxWordmark } from '@/components/app-logo';
import { STYLEGUIDE_SECTIONS } from '@/components/styleguide/sections';
import type { Appearance } from '@/hooks/use-appearance';
import { useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';

const THEMES: { value: Appearance; label: string; icon: LucideIcon }[] = [
    { value: 'light', label: 'Claro', icon: Sun },
    { value: 'dark', label: 'Oscuro', icon: Moon },
    { value: 'system', label: 'Sistema', icon: Monitor },
];

/** Conmutador de tema (usa el hook existente; solo guarda la preferencia en este navegador). */
export function ThemeSwitcher() {
    const { appearance, updateAppearance } = useAppearance();

    return (
        <div
            role="group"
            aria-label="Tema"
            className="inline-flex gap-0.5 rounded-[3px] border bg-background p-0.5"
        >
            {THEMES.map(({ value, label, icon: Icon }) => (
                <button
                    key={value}
                    type="button"
                    aria-pressed={appearance === value}
                    onClick={() => updateAppearance(value)}
                    className={cn(
                        'inline-flex h-8 items-center gap-1.5 rounded-[2px] px-2.5 text-sm transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                        appearance === value
                            ? 'bg-accent font-medium text-accent-foreground'
                            : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                    )}
                >
                    <Icon aria-hidden="true" className="size-4" />
                    <span className="sr-only sm:not-sr-only">{label}</span>
                </button>
            ))}
        </div>
    );
}

function SectionLinks({ className }: { className?: string }) {
    return (
        <ul className={cn('grid gap-0.5', className)}>
            {STYLEGUIDE_SECTIONS.map((section) => (
                <li key={section.id}>
                    <a
                        href={`#${section.id}`}
                        className="block rounded-[3px] px-2 py-1.5 text-sm text-muted-foreground outline-none hover:bg-muted hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    >
                        {section.label}
                    </a>
                </li>
            ))}
        </ul>
    );
}

/** Layout propio de /styleguide: se muestra con o sin sesión, sin la barra lateral de la app. */
export default function StyleguideLayout({
    children,
}: {
    children: ReactNode;
}) {
    return (
        <div className="min-h-svh bg-background text-foreground">
            <a
                href="#contenido"
                className="sr-only z-50 rounded-[3px] bg-primary px-3 py-2 text-primary-foreground focus:not-sr-only focus:fixed focus:top-2 focus:left-2"
            >
                Saltar al contenido
            </a>

            <header className="sticky top-0 z-40 border-b bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/80">
                <div className="mx-auto flex h-14 max-w-7xl items-center justify-between gap-3 px-4 sm:px-6">
                    <div className="flex min-w-0 items-center gap-3">
                        <AudaxWordmark className="h-4" />
                        <span className="truncate text-sm text-muted-foreground">
                            Guía de estilo
                        </span>
                    </div>
                    <ThemeSwitcher />
                </div>
            </header>

            <div className="mx-auto grid max-w-7xl gap-6 px-4 py-6 sm:px-6 lg:grid-cols-[12rem_minmax(0,1fr)] lg:gap-10 lg:py-10">
                <details className="group rounded-[3px] border lg:hidden">
                    <summary className="flex cursor-pointer list-none items-center justify-between px-3 py-2 text-sm font-medium">
                        Secciones
                        <ChevronDown
                            aria-hidden="true"
                            className="size-4 transition-transform group-open:rotate-180"
                        />
                    </summary>
                    <nav
                        aria-label="Secciones de la guía"
                        className="border-t p-1"
                    >
                        <SectionLinks />
                    </nav>
                </details>

                <nav
                    aria-label="Secciones de la guía"
                    className="hidden lg:sticky lg:top-20 lg:block lg:self-start"
                >
                    <SectionLinks />
                </nav>

                <main id="contenido" className="min-w-0">
                    {children}
                </main>
            </div>
        </div>
    );
}
