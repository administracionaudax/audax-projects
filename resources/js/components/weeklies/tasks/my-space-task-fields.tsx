import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { t } from '@/lib/i18n';
import { catalogClients } from '@/lib/my-space-tasks';
import { cn } from '@/lib/utils';
import type { MySpaceTaskProject } from '@/types/weeklies';

const ALL = '__all';
const NONE = '__none';

type FieldProps = {
    id?: string;
    invalid?: boolean;
    disabled?: boolean;
    className?: string;
    'aria-label'?: string;
};

/**
 * Cliente de la tarea (el selector «Cliente» del original): filtra los proyectos. undefined = todos;
 * null = proyectos internos, sin cliente.
 */
export function ClientPicker({
    projects,
    value,
    onChange,
    ...props
}: FieldProps & {
    projects: MySpaceTaskProject[];
    value: number | null | undefined;
    onChange: (clientId: number | null) => void;
}) {
    const clients = catalogClients(projects);

    return (
        <Select
            value={
                value === undefined
                    ? ALL
                    : value === null
                      ? NONE
                      : String(value)
            }
            onValueChange={(next) =>
                next !== ALL && onChange(next === NONE ? null : Number(next))
            }
            disabled={props.disabled}
        >
            <SelectTrigger
                id={props.id}
                aria-label={props['aria-label']}
                className={cn('w-full', props.className)}
            >
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={ALL} disabled>
                    {t('my_space.tasks.field.client_any')}
                </SelectItem>
                {clients.map((client) => (
                    <SelectItem
                        key={client.id ?? NONE}
                        value={client.id === null ? NONE : String(client.id)}
                    >
                        {client.id === null
                            ? t('my_space.tasks.general')
                            : `${client.icon ? `${client.icon} ` : ''}${client.name}`}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

/** Proyecto de la tarea (obligatorio en Audax), por cliente. */
export function ProjectPicker({
    projects,
    value,
    onChange,
    invalid,
    ...props
}: FieldProps & {
    projects: MySpaceTaskProject[];
    value: number | null;
    onChange: (projectId: number | null) => void;
}) {
    const groups = new Map<string, MySpaceTaskProject[]>();

    for (const project of projects) {
        const name = project.client?.name ?? t('my_space.tasks.general');
        groups.set(name, [...(groups.get(name) ?? []), project]);
    }

    return (
        <Select
            value={value === null ? NONE : String(value)}
            onValueChange={(next) =>
                onChange(next === NONE ? null : Number(next))
            }
            disabled={props.disabled}
        >
            <SelectTrigger
                id={props.id}
                aria-label={props['aria-label']}
                aria-invalid={invalid ? true : undefined}
                className={cn('w-full', props.className)}
                data-test="my-space-task-project"
            >
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={NONE} disabled>
                    {projects.length === 0
                        ? t('my_space.tasks.field.no_projects')
                        : t('my_space.tasks.field.project_placeholder')}
                </SelectItem>
                {[...groups.entries()].map(([client, options]) => (
                    <SelectGroup key={client}>
                        <SelectLabel>{client}</SelectLabel>
                        {options.map((project) => (
                            <SelectItem
                                key={project.id}
                                value={String(project.id)}
                            >
                                {`${project.code} · ${project.name}`}
                            </SelectItem>
                        ))}
                    </SelectGroup>
                ))}
            </SelectContent>
        </Select>
    );
}

/** Bolsa de un proyecto de bolsas (obligatoria, SPEC §8.3). */
export function BankPicker({
    project,
    value,
    onChange,
    invalid,
    ...props
}: FieldProps & {
    project: MySpaceTaskProject;
    value: number | null;
    onChange: (bankId: number | null) => void;
}) {
    return (
        <Select
            value={value === null ? NONE : String(value)}
            onValueChange={(next) =>
                onChange(next === NONE ? null : Number(next))
            }
            disabled={props.disabled}
        >
            <SelectTrigger
                id={props.id}
                aria-label={props['aria-label']}
                aria-invalid={invalid ? true : undefined}
                className={cn('w-full', props.className)}
            >
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={NONE} disabled>
                    {project.banks.length === 0
                        ? t('my_space.tasks.field.no_banks')
                        : t('my_space.tasks.field.bank_placeholder')}
                </SelectItem>
                {project.banks.map((bank) => (
                    <SelectItem key={bank.id} value={String(bank.id)}>
                        {bank.name}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
