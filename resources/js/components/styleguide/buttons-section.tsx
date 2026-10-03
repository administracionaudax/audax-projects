import { Download, Plus, Trash2 } from 'lucide-react';
import { Section, Specimen } from '@/components/styleguide/section';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

/**
 * Variantes de marca que aún no están en components/ui/button.tsx (SPEC §3.1):
 * contorno azul y botón blanco sobre fondo oscuro. Ver open issues del integrador.
 */
export const BRAND_OUTLINE =
    'border-brand bg-transparent text-primary-text hover:bg-accent hover:text-primary-text';
export const ON_DARK =
    'bg-white text-primary hover:bg-white/90 hover:text-primary';

export function ButtonsSection() {
    return (
        <Section
            id="botones"
            title="Botones"
            description="Esquinas rectas y sin sombra (hoja de estilos de Audax). Texto en infinitivo: «Guardar», «Imputar horas», «Iniciar sesión»."
        >
            <Specimen title="Variantes">
                <div className="flex flex-wrap items-center gap-3">
                    <Button>Guardar</Button>
                    <Button variant="outline" className={BRAND_OUTLINE}>
                        Cancelar
                    </Button>
                    <Button variant="secondary">Duplicar</Button>
                    <Button variant="destructive">Eliminar</Button>
                    <Button variant="ghost">Ver detalle</Button>
                    <Button variant="link" className="text-primary-text">
                        Ver todas las tareas
                    </Button>
                </div>
            </Specimen>

            <Specimen
                title="Sobre fondo oscuro"
                note="Blanco con texto azul. Solo sobre el degradado de marca (login, portal, estados vacíos grandes)."
                className="border-0 bg-brand-gradient"
            >
                <div className="flex flex-wrap items-center gap-3">
                    <Button className={ON_DARK}>Iniciar sesión</Button>
                    <Button
                        variant="outline"
                        className="border-white/60 bg-transparent text-white hover:bg-white/10 hover:text-white"
                    >
                        Más información
                    </Button>
                </div>
            </Specimen>

            <Specimen title="Tamaños">
                <div className="flex flex-wrap items-center gap-3">
                    <Button size="sm">Pequeño</Button>
                    <Button>Mediano</Button>
                    <Button size="lg">Grande</Button>
                    <Button size="icon" aria-label="Añadir tarea">
                        <Plus />
                    </Button>
                </div>
            </Specimen>

            <Specimen title="Estados">
                <div className="flex flex-wrap items-center gap-3">
                    <Button disabled>Deshabilitado</Button>
                    <Button
                        variant="outline"
                        className={BRAND_OUTLINE}
                        disabled
                    >
                        Deshabilitado
                    </Button>
                    <Button disabled aria-busy="true">
                        <Spinner aria-hidden="true" role="presentation" />
                        Guardando…
                    </Button>
                </div>
            </Specimen>

            <Specimen title="Con icono">
                <div className="flex flex-wrap items-center gap-3">
                    <Button>
                        <Plus aria-hidden="true" />
                        Nueva tarea
                    </Button>
                    <Button variant="outline" className={BRAND_OUTLINE}>
                        <Download aria-hidden="true" />
                        Exportar XLSX
                    </Button>
                    <Button variant="destructive">
                        <Trash2 aria-hidden="true" />
                        Eliminar entrada
                    </Button>
                </div>
            </Specimen>
        </Section>
    );
}
