import { router } from '@inertiajs/react';
import { Building2, FolderKanban, Plus, X } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import type { KeyboardEvent, ReactNode, Ref } from 'react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { FOCUS_RING } from '@/lib/focus-ring';
import {
    activeToken,
    matchTargets,
    parseLine,
    removeToken,
} from '@/lib/day-plan';
import type { MatchedTarget } from '@/lib/day-plan';
import { formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { store } from '@/routes/day-plan/items';
import type {
    DayPlanTargetClient,
    DayPlanTargetProject,
    DayPlanTargets,
} from '@/types/day-plan';

/**
 * «Escribe qué vas a hacer…» (docs/PLAN-CARGAS.md §4.2, R1: tan rápido como la lista de ClickUp):
 * Intro añade la línea y deja el cursor listo para la siguiente. Atajos: `@` elige cliente, `#`
 * proyecto (lista con flechas, Intro o Tab para elegir y Escape para cerrar) y `~1:30` pone las
 * horas previstas. Cliente y proyecto quedan como etiquetas que se pueden quitar.
 */
export function LineComposer({
    date,
    targets,
    compact = false,
    autoFocus = false,
    inputRef,
    onAdded,
}: {
    date: string;
    targets: DayPlanTargets | undefined;
    compact?: boolean;
    autoFocus?: boolean;
    inputRef?: Ref<HTMLInputElement>;
    onAdded?: () => void;
}) {
    const id = useId();
    const localRef = useRef<HTMLInputElement | null>(null);
    const [value, setValue] = useState('');
    const [caret, setCaret] = useState(0);
    const [client, setClient] = useState<DayPlanTargetClient | null>(null);
    const [project, setProject] = useState<DayPlanTargetProject | null>(null);
    const [highlight, setHighlight] = useState(0);
    const [dismissed, setDismissed] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);

    const token = activeToken(value, caret);
    const matches =
        token && dismissed !== token.start
            ? matchTargets(token.kind, token.query, targets)
            : [];
    const open = matches.length > 0;
    const active = Math.min(highlight, Math.max(matches.length - 1, 0));
    const parsed = parseLine(value);
    const listId = `${id}-targets`;
    const errorId = `${id}-error`;
    const hintId = `${id}-hint`;

    const setRefs = (node: HTMLInputElement | null) => {
        localRef.current = node;

        if (typeof inputRef === 'function') {
            inputRef(node);
        } else if (inputRef) {
            inputRef.current = node;
        }
    };

    const pick = (match: MatchedTarget) => {
        if (!token) {
            return;
        }

        const next = removeToken(value, token);

        if (match.type === 'client') {
            setClient(match.client);

            if (project && project.client_id !== match.client.id) {
                setProject(null);
            }
        } else {
            setProject(match.project);
            setClient(
                match.project.client_id !== null
                    ? {
                          id: match.project.client_id,
                          name: match.project.client_name ?? '',
                      }
                    : null,
            );
        }

        setValue(next);
        setHighlight(0);
        requestAnimationFrame(() => {
            localRef.current?.focus();
            localRef.current?.setSelectionRange(token.start, token.start);
            setCaret(token.start);
        });
    };

    const submit = () => {
        if (processing) {
            return;
        }

        if (parsed.text === '') {
            setError(t('day_plan.composer.errors.text'));

            return;
        }

        if (parsed.invalidDuration) {
            setError(t('day_plan.composer.errors.duration'));

            return;
        }

        router.post(
            store.url(),
            {
                date,
                text: parsed.text,
                client_id: project ? null : (client?.id ?? null),
                project_id: project?.id ?? null,
                planned_minutes: parsed.minutes,
            },
            {
                preserveScroll: true,
                preserveState: true,
                errorBag: 'dayPlan',
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => {
                    setValue('');
                    setCaret(0);
                    setError(null);
                    setClient(null);
                    setProject(null);
                    onAdded?.();
                    requestAnimationFrame(() => localRef.current?.focus());
                },
                onError: (errors) =>
                    setError(
                        Object.values(errors).filter(Boolean).join(' ') ||
                            t('day_plan.composer.errors.text'),
                    ),
            },
        );
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (open) {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                setHighlight((active + 1) % matches.length);

                return;
            }

            if (event.key === 'ArrowUp') {
                event.preventDefault();
                setHighlight((active - 1 + matches.length) % matches.length);

                return;
            }

            if (event.key === 'Enter' || event.key === 'Tab') {
                event.preventDefault();
                pick(matches[active]);

                return;
            }

            if (event.key === 'Escape') {
                event.preventDefault();
                setDismissed(token?.start ?? null);

                return;
            }
        }

        if (event.key === 'Enter') {
            event.preventDefault();
            submit();
        }
    };

    return (
        <div className="grid gap-1.5" data-test="day-plan-composer">
            <div className="relative flex items-center gap-2">
                <Plus
                    aria-hidden="true"
                    className="size-4 shrink-0 text-muted-foreground"
                />
                <label htmlFor={`${id}-input`} className="sr-only">
                    {t('day_plan.composer.label')}
                </label>
                <Input
                    ref={setRefs}
                    id={`${id}-input`}
                    value={value}
                    autoFocus={autoFocus}
                    autoComplete="off"
                    maxLength={240}
                    placeholder={t('day_plan.composer.placeholder')}
                    role="combobox"
                    aria-autocomplete="list"
                    aria-expanded={open}
                    aria-controls={open ? listId : undefined}
                    aria-activedescendant={
                        open ? `${listId}-${active}` : undefined
                    }
                    aria-invalid={error ? true : undefined}
                    aria-describedby={
                        cn(
                            compact ? undefined : hintId,
                            error ? errorId : undefined,
                        ) || undefined
                    }
                    // Sin disabled: el foco se queda en la caja para escribir la siguiente línea.
                    aria-busy={processing || undefined}
                    onChange={(event) => {
                        setValue(event.target.value);
                        setCaret(event.target.selectionStart ?? 0);
                        setHighlight(0);
                        setDismissed(null);
                        setError(null);
                    }}
                    onSelect={(event) =>
                        setCaret(event.currentTarget.selectionStart ?? 0)
                    }
                    onKeyDown={onKeyDown}
                    onBlur={() => setDismissed(token?.start ?? null)}
                    className="h-9"
                    data-test="day-plan-composer-input"
                />
                {open ? (
                    <ul
                        id={listId}
                        role="listbox"
                        aria-label={
                            token?.kind === '@'
                                ? t('day_plan.composer.clients')
                                : t('day_plan.composer.projects')
                        }
                        className="absolute top-full right-0 left-6 z-20 mt-1 max-h-72 overflow-y-auto border bg-popover py-1 text-sm text-popover-foreground"
                        data-test="day-plan-composer-options"
                    >
                        {matches.map((match, index) => (
                            <li
                                key={
                                    match.type === 'client'
                                        ? `c${match.client.id}`
                                        : `p${match.project.id}`
                                }
                                id={`${listId}-${index}`}
                                role="option"
                                aria-selected={index === active}
                                className={cn(
                                    'flex cursor-pointer items-center gap-2 px-3 py-1.5',
                                    index === active && 'bg-accent',
                                )}
                                onMouseDown={(event) => {
                                    event.preventDefault();
                                    pick(match);
                                }}
                            >
                                {match.type === 'client' ? (
                                    <>
                                        <Building2
                                            aria-hidden="true"
                                            className="size-3.5 text-muted-foreground"
                                        />
                                        {match.client.name}
                                    </>
                                ) : (
                                    <>
                                        <span
                                            aria-hidden="true"
                                            className="size-2 shrink-0 rounded-full"
                                            style={{
                                                backgroundColor:
                                                    match.project.color,
                                            }}
                                        />
                                        <span className="truncate">
                                            {match.project.code} ·{' '}
                                            {match.project.name}
                                        </span>
                                        {match.project.client_name ? (
                                            <span className="truncate text-xs text-muted-foreground">
                                                {match.project.client_name}
                                            </span>
                                        ) : null}
                                    </>
                                )}
                            </li>
                        ))}
                    </ul>
                ) : null}
            </div>
            {client || project || parsed.minutes !== null ? (
                <div className="flex flex-wrap items-center gap-1.5 pl-6 text-xs">
                    {client && !project ? (
                        <Chip
                            icon={<Building2 className="size-3" />}
                            label={client.name}
                            removeLabel={t('day_plan.composer.remove_client', {
                                name: client.name,
                            })}
                            onRemove={() => setClient(null)}
                        />
                    ) : null}
                    {project ? (
                        <Chip
                            icon={<FolderKanban className="size-3" />}
                            label={`${project.code} · ${project.name}`}
                            removeLabel={t('day_plan.composer.remove_project', {
                                name: project.code,
                            })}
                            onRemove={() => {
                                setProject(null);
                                setClient(null);
                            }}
                        />
                    ) : null}
                    {parsed.minutes !== null ? (
                        <span className="tabular text-muted-foreground">
                            {t('day_plan.composer.planned', {
                                time: formatMinutes(parsed.minutes),
                            })}
                        </span>
                    ) : null}
                </div>
            ) : null}
            {compact ? null : (
                <p id={hintId} className="pl-6 text-xs text-muted-foreground">
                    {t('day_plan.composer.hint')}
                </p>
            )}
            <InputError
                id={errorId}
                message={error ?? undefined}
                className="pl-6"
            />
        </div>
    );
}

function Chip({
    icon,
    label,
    removeLabel,
    onRemove,
}: {
    icon: ReactNode;
    label: string;
    removeLabel: string;
    onRemove: () => void;
}) {
    return (
        <span className="inline-flex max-w-full items-center gap-1 border bg-muted px-1.5 py-0.5">
            <span aria-hidden="true">{icon}</span>
            <span className="truncate">{label}</span>
            <button
                type="button"
                onClick={onRemove}
                aria-label={removeLabel}
                className={cn(
                    'text-muted-foreground hover:text-foreground',
                    FOCUS_RING,
                )}
            >
                <X aria-hidden="true" className="size-3" />
            </button>
        </span>
    );
}
