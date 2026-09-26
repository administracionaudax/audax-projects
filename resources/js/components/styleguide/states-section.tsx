import { CircleAlert, FolderOpen, Inbox, Plus, RotateCcw } from 'lucide-react';
import { toast } from 'sonner';
import {
    BRAND_OUTLINE,
    ON_DARK,
} from '@/components/styleguide/buttons-section';
import { Section, Specimen } from '@/components/styleguide/section';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';

export function StatesSection() {
    return (
        <Section
            id="estados"
            title="Estados y avisos"
            description="Vacío, cargando y error, más las notificaciones emergentes. El degradado de marca solo aparece en el estado vacío grande."
        >
            <Specimen
                title="Estado vacío grande"
                className="border-0 p-0 sm:p-0"
            >
                <div className="flex flex-col items-start gap-4 rounded-[3px] px-6 py-10 text-white bg-brand-gradient sm:px-10 sm:py-14">
                    <FolderOpen
                        aria-hidden="true"
                        className="size-8 text-white"
                    />
                    <div className="grid max-w-lg gap-2">
                        <p className="text-3xl">Todavía no hay proyectos</p>
                        <p className="text-white/80">
                            Crea el primero para empezar a planificar tareas e
                            imputar horas. Puedes partir de una plantilla.
                        </p>
                    </div>
                    <Button className={ON_DARK}>
                        <Plus aria-hidden="true" />
                        Crear proyecto
                    </Button>
                </div>
            </Specimen>

            <div className="grid gap-8 lg:grid-cols-2">
                <Specimen title="Estado vacío en tarjeta">
                    <div className="flex flex-col items-center gap-2 py-6 text-center">
                        <Inbox
                            aria-hidden="true"
                            className="size-6 text-muted-foreground"
                        />
                        <p className="text-lg">
                            No tienes tareas para{' '}
                            <span className="text-primary-text">hoy</span>
                        </p>
                        <p className="max-w-xs text-sm text-muted-foreground">
                            Las tareas con fecha de hoy aparecerán aquí.
                        </p>
                        <Button
                            variant="outline"
                            size="sm"
                            className={BRAND_OUTLINE}
                        >
                            Ver todas mis tareas
                        </Button>
                    </div>
                </Specimen>

                <Specimen title="Cargando">
                    <div
                        className="grid gap-3"
                        role="status"
                        aria-busy="true"
                        aria-label="Cargando tareas"
                    >
                        {[0, 1, 2].map((row) => (
                            <div key={row} className="flex items-center gap-3">
                                <Skeleton className="size-8 rounded-[3px]" />
                                <div className="grid flex-1 gap-1.5">
                                    <Skeleton className="h-3.5 w-3/4 rounded-[2px]" />
                                    <Skeleton className="h-3 w-1/2 rounded-[2px]" />
                                </div>
                                <Skeleton className="h-3.5 w-12 rounded-[2px]" />
                            </div>
                        ))}
                    </div>
                </Specimen>
            </div>

            <Specimen title="Error">
                <Alert className="rounded-[3px] border-transparent bg-danger-soft [&>svg]:text-danger">
                    <CircleAlert aria-hidden="true" />
                    <AlertTitle className="text-danger">
                        No se han podido cargar las horas
                    </AlertTitle>
                    <AlertDescription className="text-foreground">
                        <p>
                            Comprueba la conexión y vuelve a intentarlo. Si el
                            problema sigue, avisa a administración.
                        </p>
                        <Button
                            variant="outline"
                            size="sm"
                            className={`mt-2 ${BRAND_OUTLINE}`}
                        >
                            <RotateCcw aria-hidden="true" />
                            Reintentar
                        </Button>
                    </AlertDescription>
                </Alert>
            </Specimen>

            <Specimen
                title="Notificaciones emergentes (toasts)"
                note="Abajo a la derecha. Frases cortas y en positivo; los errores dicen qué hacer."
            >
                <div className="flex flex-wrap gap-3">
                    <Button
                        variant="outline"
                        className={BRAND_OUTLINE}
                        onClick={() =>
                            toast.success('Horas guardadas', {
                                description:
                                    'Se han imputado 1:30 en «Maquetación de la home».',
                            })
                        }
                    >
                        Éxito
                    </Button>
                    <Button
                        variant="outline"
                        className={BRAND_OUTLINE}
                        onClick={() =>
                            toast.warning('Esta bolsa está agotada', {
                                description:
                                    'Estas horas se registrarán como exceso.',
                            })
                        }
                    >
                        Aviso
                    </Button>
                    <Button
                        variant="outline"
                        className={BRAND_OUTLINE}
                        onClick={() =>
                            toast.error('No se ha podido enviar la semana', {
                                description:
                                    'Revisa los días marcados y vuelve a intentarlo.',
                            })
                        }
                    >
                        Error
                    </Button>
                    <Button
                        variant="outline"
                        className={BRAND_OUTLINE}
                        onClick={() =>
                            toast('Semana enviada', {
                                description:
                                    'Tu responsable recibirá un aviso para aprobarla.',
                            })
                        }
                    >
                        Información
                    </Button>
                </div>
            </Specimen>
        </Section>
    );
}
