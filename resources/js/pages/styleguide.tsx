import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { ButtonsSection } from '@/components/styleguide/buttons-section';
import { CardsSection } from '@/components/styleguide/cards-section';
import { ChartsSection } from '@/components/styleguide/charts-section';
import { ColorTokens } from '@/components/styleguide/color-tokens';
import { FormsSection } from '@/components/styleguide/forms-section';
import { HourBanksSection } from '@/components/styleguide/hour-banks-section';
import { Section } from '@/components/styleguide/section';
import { StatesSection } from '@/components/styleguide/states-section';
import StyleguideLayout from '@/components/styleguide/styleguide-layout';
import { TableSection } from '@/components/styleguide/table-section';
import { TypographySection } from '@/components/styleguide/typography-section';
import { WorkloadSection } from '@/components/styleguide/workload-section';

/**
 * Guía de estilo (SPEC §3.1). Se renderiza con o sin sesión, con su propio layout
 * (sin barra lateral), para aprobar el tema antes de construir pantallas.
 */
export default function Styleguide() {
    return (
        <>
            <Head title="Guía de estilo" />

            <header className="mb-10 grid gap-2">
                <h1 className="text-3xl text-brand-navy sm:text-[32px] dark:text-foreground">
                    Guía de estilo de{' '}
                    <span className="text-brand">Audax Proyectos</span>
                </h1>
                <p className="max-w-3xl text-[15px] text-muted-foreground">
                    Colores, tipografía y componentes del tema Audax Studio en
                    claro y oscuro. Todo lo que ves aquí es lo que usarán las
                    pantallas de la app: cualquier cambio de diseño empieza en
                    esta página.
                </p>
            </header>

            <Section
                id="colores"
                title="Colores"
                description="Tokens del tema leídos de la hoja de estilos y contraste WCAG calculado en tu navegador. Texto: 4,5:1; elementos gráficos, iconos y bordes de campos: 3:1. El color nunca es el único indicador."
            >
                <ColorTokens />
            </Section>

            <TypographySection />
            <ButtonsSection />
            <FormsSection />
            <TableSection />
            <CardsSection />
            <WorkloadSection />
            <HourBanksSection />
            <ChartsSection />
            <StatesSection />
        </>
    );
}

Styleguide.layout = (page: ReactNode) => (
    <StyleguideLayout>{page}</StyleguideLayout>
);
