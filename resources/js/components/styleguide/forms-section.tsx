import { CircleAlert, Kanban, List } from 'lucide-react';
import { useId, useState } from 'react';
import { parseDuration } from '@/components/styleguide/duration';
import { Section, Specimen } from '@/components/styleguide/section';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { formatMinutes } from '@/lib/format';
import { cn } from '@/lib/utils';

/** Mismo aspecto que <Input> para el textarea (el kit no trae uno propio). */
const TEXTAREA =
    'flex min-h-20 w-full rounded-[3px] border border-input bg-transparent px-3 py-2 text-base outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 md:text-sm';

function DurationField() {
    const id = useId();
    const [value, setValue] = useState('1:30');
    const minutes = parseDuration(value);
    const invalid = value.trim() !== '' && minutes === null;

    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>Duración</Label>
            <Input
                id={id}
                value={value}
                onChange={(event) => setValue(event.target.value)}
                inputMode="decimal"
                autoComplete="off"
                className="tabular w-32"
                aria-invalid={invalid || undefined}
                aria-describedby={`${id}-ayuda`}
            />
            <p
                id={`${id}-ayuda`}
                className={cn(
                    'text-sm',
                    invalid
                        ? 'text-destructive-foreground'
                        : 'text-muted-foreground',
                )}
            >
                {invalid
                    ? 'Escribe la duración como 1:30, 1,5, 90m o 1h30 (máximo 24 h).'
                    : minutes !== null
                      ? `Se guardará como ${formatMinutes(minutes)} (${minutes} minutos).`
                      : 'Acepta 1:30, 1,5, 90m o 1h30.'}
            </p>
        </div>
    );
}

export function FormsSection() {
    const [billable, setBillable] = useState('yes');

    return (
        <Section
            id="formularios"
            title="Formularios"
            description="Pocos campos obligatorios y valores por defecto razonables. Cada campo lleva etiqueta visible; los errores se anuncian junto al campo, con icono y texto."
        >
            <Specimen title="Campos de texto">
                <div className="grid max-w-xl gap-5">
                    <div className="grid gap-2">
                        <Label htmlFor="sg-title">Título de la tarea</Label>
                        <Input
                            id="sg-title"
                            placeholder="Por ejemplo: Maquetar la home"
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="sg-description">Descripción</Label>
                        <textarea
                            id="sg-description"
                            className={TEXTAREA}
                            placeholder="Opcional"
                            rows={3}
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="sg-disabled">Cliente</Label>
                        <Input
                            id="sg-disabled"
                            value="Hoteles Mediterráneo"
                            disabled
                            readOnly
                        />
                    </div>
                </div>
            </Specimen>

            <Specimen title="Ayuda y error">
                <div className="grid max-w-xl gap-5">
                    <div className="grid gap-2">
                        <Label htmlFor="sg-estimate">Estimación</Label>
                        <Input
                            id="sg-estimate"
                            defaultValue="8:00"
                            className="tabular w-32"
                            aria-describedby="sg-estimate-help"
                        />
                        <p
                            id="sg-estimate-help"
                            className="text-sm text-muted-foreground"
                        >
                            Horas previstas para la tarea completa. Sirve para
                            calcular la carga.
                        </p>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="sg-email">Correo electrónico</Label>
                        <Input
                            id="sg-email"
                            type="email"
                            defaultValue="laura@audax"
                            aria-invalid="true"
                            aria-describedby="sg-email-error"
                        />
                        <p
                            id="sg-email-error"
                            className="flex items-center gap-1.5 text-sm text-destructive-foreground"
                        >
                            <CircleAlert
                                aria-hidden="true"
                                className="size-4 shrink-0"
                            />
                            Escribe un correo válido, por ejemplo
                            laura@audaxstudio.com.
                        </p>
                    </div>
                </div>
            </Specimen>

            <Specimen
                title="Duración"
                note="El campo de horas acepta varios formatos y confirma cómo se guardará."
            >
                <DurationField />
            </Specimen>

            <Specimen title="Selección">
                <div className="grid max-w-xl gap-6">
                    <div className="grid gap-2">
                        <Label htmlFor="sg-department">Departamento</Label>
                        <Select defaultValue="development">
                            <SelectTrigger
                                id="sg-department"
                                className="w-full sm:w-64"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="design">Diseño</SelectItem>
                                <SelectItem value="development">
                                    Desarrollo
                                </SelectItem>
                                <SelectItem value="marketing">
                                    Marketing
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="flex items-center gap-3">
                        <Checkbox id="sg-notify" defaultChecked />
                        <Label htmlFor="sg-notify" className="font-normal">
                            Avisarme cuando la bolsa supere el 90 %
                        </Label>
                    </div>

                    <fieldset className="grid gap-2">
                        <legend className="mb-1 text-sm font-medium">
                            Facturable
                        </legend>
                        {[
                            {
                                value: 'yes',
                                label: 'Sí, hereda del tipo de tarea',
                            },
                            { value: 'no', label: 'No facturable' },
                        ].map((option) => (
                            <label
                                key={option.value}
                                className="flex items-center gap-2 text-sm"
                            >
                                <input
                                    type="radio"
                                    name="sg-billable"
                                    value={option.value}
                                    checked={billable === option.value}
                                    onChange={() => setBillable(option.value)}
                                    className="size-4 accent-[var(--primary)]"
                                />
                                {option.label}
                            </label>
                        ))}
                    </fieldset>

                    <div className="grid gap-2">
                        <span id="sg-view" className="text-sm font-medium">
                            Vista
                        </span>
                        <ToggleGroup
                            type="single"
                            defaultValue="list"
                            variant="outline"
                            aria-labelledby="sg-view"
                            className="w-fit"
                        >
                            <ToggleGroupItem
                                value="list"
                                className="gap-1.5 px-3"
                            >
                                <List aria-hidden="true" /> Lista
                            </ToggleGroupItem>
                            <ToggleGroupItem
                                value="kanban"
                                className="gap-1.5 px-3"
                            >
                                <Kanban aria-hidden="true" /> Kanban
                            </ToggleGroupItem>
                        </ToggleGroup>
                    </div>

                    <p className="text-sm text-muted-foreground">
                        El kit todavía no incluye un interruptor (switch);
                        mientras tanto se usa la casilla.
                    </p>
                </div>
            </Specimen>
        </Section>
    );
}
