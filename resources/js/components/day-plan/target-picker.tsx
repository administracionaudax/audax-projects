import { Building2, Check, ChevronsUpDown, Inbox } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { DayPlanTargets } from '@/types/day-plan';

export type TargetValue = {
    client_id: number | null;
    project_id: number | null;
};

/**
 * Cliente o proyecto de una línea (opcional): «General / Interno», un cliente o un proyecto, con
 * buscador. Elegir un proyecto fija su cliente (lo hace el servidor).
 */
export function TargetPicker({
    id,
    value,
    targets,
    current,
    onChange,
}: {
    id?: string;
    value: TargetValue;
    targets: DayPlanTargets | undefined;
    /** Lo que muestra la línea aunque ya no esté en el catálogo (proyecto archivado…). */
    current: string | null;
    onChange: (value: TargetValue) => void;
}) {
    const [open, setOpen] = useState(false);
    const project = targets?.projects.find(
        (item) => item.id === value.project_id,
    );
    const client = targets?.clients.find((item) => item.id === value.client_id);
    const label = project
        ? `${project.code} · ${project.name}`
        : client
          ? client.name
          : value.project_id || value.client_id
            ? (current ?? '')
            : t('day_plan.line.general');

    const choose = (next: TargetValue) => {
        setOpen(false);
        onChange(next);
    };

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    id={id}
                    type="button"
                    variant="field"
                    role="combobox"
                    aria-expanded={open}
                    className="w-full justify-between"
                    data-test="day-plan-target"
                >
                    <span className="truncate">{label}</span>
                    <ChevronsUpDown
                        aria-hidden="true"
                        className="size-4 opacity-60"
                    />
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-80 p-0" align="start">
                <Command>
                    <CommandInput
                        placeholder={t('day_plan.target.search')}
                        aria-label={t('day_plan.target.search')}
                    />
                    <CommandList>
                        <CommandEmpty>
                            {t('day_plan.target.empty')}
                        </CommandEmpty>
                        <CommandGroup>
                            <CommandItem
                                value={`__general ${t('day_plan.line.general')}`}
                                onSelect={() =>
                                    choose({
                                        client_id: null,
                                        project_id: null,
                                    })
                                }
                            >
                                <Inbox aria-hidden="true" />
                                {t('day_plan.line.general')}
                                {!value.client_id && !value.project_id ? (
                                    <Check
                                        aria-hidden="true"
                                        className="ml-auto"
                                    />
                                ) : null}
                            </CommandItem>
                        </CommandGroup>
                        {targets && targets.projects.length > 0 ? (
                            <CommandGroup
                                heading={t('day_plan.target.projects')}
                            >
                                {targets.projects.map((item) => (
                                    <CommandItem
                                        key={`p${item.id}`}
                                        value={`${item.code} ${item.name} ${item.client_name ?? ''} p${item.id}`}
                                        onSelect={() =>
                                            choose({
                                                client_id: item.client_id,
                                                project_id: item.id,
                                            })
                                        }
                                    >
                                        <span
                                            aria-hidden="true"
                                            className="size-2 shrink-0 rounded-full"
                                            style={{
                                                backgroundColor: item.color,
                                            }}
                                        />
                                        <span className="truncate">
                                            {item.code} · {item.name}
                                        </span>
                                        {value.project_id === item.id ? (
                                            <Check
                                                aria-hidden="true"
                                                className="ml-auto"
                                            />
                                        ) : null}
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        ) : null}
                        {targets && targets.clients.length > 0 ? (
                            <CommandGroup
                                heading={t('day_plan.target.clients')}
                            >
                                {targets.clients.map((item) => (
                                    <CommandItem
                                        key={`c${item.id}`}
                                        value={`${item.name} c${item.id}`}
                                        onSelect={() =>
                                            choose({
                                                client_id: item.id,
                                                project_id: null,
                                            })
                                        }
                                    >
                                        <Building2 aria-hidden="true" />
                                        <span className="truncate">
                                            {item.name}
                                        </span>
                                        {!value.project_id &&
                                        value.client_id === item.id ? (
                                            <Check
                                                aria-hidden="true"
                                                className={cn('ml-auto')}
                                            />
                                        ) : null}
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        ) : null}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
