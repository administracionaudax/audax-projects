import { Link, router, usePage } from '@inertiajs/react';
import { CircleAlert } from 'lucide-react';
import { useId, useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import { DatePicker } from '@/components/domain/date-picker';
import InputError from '@/components/input-error';
import { PrioritySelect } from '@/components/tasks/task-fields';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    BankPicker,
    ClientPicker,
    ProjectPicker,
} from '@/components/weeklies/tasks/my-space-task-fields';
import { useRequiredUser } from '@/hooks/use-auth';
import { t } from '@/lib/i18n';
import { defaultBank, projectsOfClient } from '@/lib/my-space-tasks';
import { index as clientsIndex } from '@/routes/clients';
import { store as storeTask, update as updateTask } from '@/routes/tasks';
import type { TaskPriority } from '@/types';
import type { MySpaceTask, MySpaceTaskProject } from '@/types/weeklies';

/** Lo que se recarga tras crear, editar o borrar una tarea en «Mi espacio». */
export const MY_TASKS_RELOAD = ['my_tasks'];

type Errors = Partial<
    Record<
        'title' | 'project' | 'hour_bank_id' | 'due_date' | 'general',
        string
    >
>;

function serverErrors(errors: Record<string, string>): Errors {
    const known = ['title', 'hour_bank_id', 'due_date'] as const;
    const mapped: Errors = {};
    const general: string[] = [];

    for (const [key, message] of Object.entries(errors)) {
        if ((known as readonly string[]).includes(key)) {
            mapped[key as (typeof known)[number]] = message;
        } else {
            general.push(message);
        }
    }

    if (general.length > 0) {
        mapped.general = general.join(' ');
    }

    return mapped;
}

/**
 * «Nueva tarea» de «Mi espacio» (F-058): la descripción y el cliente del original, más lo que pide una
 * tarea de Audax: el proyecto (obligatorio) y, en uno de bolsas, la bolsa; la prioridad y la entrega
 * (F-063), opcionales. Es para mí. Intro guarda. Se crea por la ruta de siempre (tasks.store,
 * TaskWriter).
 */
export function NewTaskDialog({
    open,
    onOpenChange,
    projects,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    projects: MySpaceTaskProject[];
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="max-h-[calc(100dvh-2rem)] overflow-y-auto sm:max-w-lg"
                data-test="my-space-task-dialog"
            >
                {open ? (
                    <NewTaskForm
                        projects={projects}
                        onDone={() => onOpenChange(false)}
                    />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

function NewTaskForm({
    projects,
    onDone,
}: {
    projects: MySpaceTaskProject[];
    onDone: () => void;
}) {
    const id = useId();
    const user = useRequiredUser();
    const viewClients = usePage().props.auth?.can?.viewClients === true;
    const [title, setTitle] = useState('');
    const [clientId, setClientId] = useState<number | null | undefined>(
        undefined,
    );
    const [projectId, setProjectId] = useState<number | null>(null);
    const [bankId, setBankId] = useState<number | null>(null);
    const [priority, setPriority] = useState<TaskPriority>('normal');
    const [dueDate, setDueDate] = useState<string | null>(null);
    const [errors, setErrors] = useState<Errors>({});
    const [processing, setProcessing] = useState(false);
    const project = projects.find((p) => p.id === projectId);
    const field = (name: string) => `${id}-${name}`;

    const chooseClient = (next: number | null) => {
        setClientId(next);
        const options = projectsOfClient(projects, next);
        const only = options.length === 1 ? options[0] : undefined;
        setProjectId(only?.id ?? null);
        setBankId(defaultBank(only));
    };

    const chooseProject = (next: number | null) => {
        setProjectId(next);
        setBankId(defaultBank(projects.find((p) => p.id === next)));
        setErrors((current) => ({ ...current, project: undefined }));
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (processing) {
            return;
        }

        const found: Errors = {};

        if (title.trim() === '') {
            found.title = t('my_space.tasks.errors.title');
        }

        if (!project) {
            found.project = t('my_space.tasks.errors.project');
        } else if (project.uses_banks && bankId === null) {
            found.hour_bank_id = t('my_space.tasks.errors.bank');
        }

        setErrors(found);

        if (Object.keys(found).length > 0 || !project) {
            return;
        }

        router.post(
            storeTask.url(project.id),
            {
                title: title.trim(),
                priority,
                due_date: dueDate,
                assignee_user_id: user.id,
                ...(project.uses_banks ? { hour_bank_id: bankId } : {}),
            },
            {
                preserveScroll: true,
                preserveState: true,
                only: MY_TASKS_RELOAD,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => {
                    toast.success(
                        t('my_space.tasks.toast.created', {
                            task: title.trim(),
                        }),
                    );
                    onDone();
                },
                onError: (server) =>
                    setErrors(serverErrors(server as Record<string, string>)),
            },
        );
    };

    return (
        <form onSubmit={submit} noValidate className="grid gap-4">
            <DialogHeader>
                <DialogTitle>{t('my_space.tasks.new.title')}</DialogTitle>
                <DialogDescription>
                    {t('my_space.tasks.new.description')}
                </DialogDescription>
            </DialogHeader>

            {errors.general ? (
                <Alert variant="destructive" role="alert">
                    <CircleAlert aria-hidden="true" />
                    <AlertDescription>{errors.general}</AlertDescription>
                </Alert>
            ) : null}

            <div className="grid gap-2">
                <Label htmlFor={field('title')}>
                    {t('my_space.tasks.field.title')}
                </Label>
                <Input
                    id={field('title')}
                    value={title}
                    onChange={(event) => setTitle(event.target.value)}
                    placeholder={t('my_space.tasks.field.title_placeholder')}
                    maxLength={255}
                    autoComplete="off"
                    autoFocus
                    aria-invalid={errors.title ? true : undefined}
                    data-test="my-space-task-title"
                />
                <InputError message={errors.title} />
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid content-start gap-2">
                    <Label htmlFor={field('client')}>
                        {t('my_space.tasks.field.client')}
                    </Label>
                    <ClientPicker
                        id={field('client')}
                        projects={projects}
                        value={clientId}
                        onChange={chooseClient}
                    />
                    <p
                        className="text-xs text-muted-foreground"
                        data-test="my-space-task-missing-client"
                    >
                        {t('my_space.tasks.field.missing_client')}
                        {viewClients ? (
                            <>
                                {' '}
                                <Link
                                    href={clientsIndex.url()}
                                    className="underline underline-offset-2"
                                >
                                    {t(
                                        'my_space.tasks.field.missing_client_link',
                                    )}
                                </Link>
                            </>
                        ) : null}
                    </p>
                </div>
                <div className="grid content-start gap-2">
                    <Label htmlFor={field('project')}>
                        {t('my_space.tasks.field.project')}
                    </Label>
                    <ProjectPicker
                        id={field('project')}
                        projects={
                            clientId === undefined
                                ? projects
                                : projectsOfClient(projects, clientId)
                        }
                        value={projectId}
                        onChange={chooseProject}
                        invalid={Boolean(errors.project)}
                    />
                    <InputError message={errors.project} />
                </div>
                {project?.uses_banks ? (
                    <div className="grid content-start gap-2">
                        <Label htmlFor={field('bank')}>
                            {t('my_space.tasks.field.bank')}
                        </Label>
                        <BankPicker
                            id={field('bank')}
                            project={project}
                            value={bankId}
                            onChange={setBankId}
                            invalid={Boolean(errors.hour_bank_id)}
                        />
                        <InputError message={errors.hour_bank_id} />
                    </div>
                ) : null}
                <div className="grid content-start gap-2">
                    <Label htmlFor={field('priority')}>
                        {t('my_space.tasks.field.priority')}
                    </Label>
                    <PrioritySelect
                        id={field('priority')}
                        value={priority}
                        onChange={setPriority}
                    />
                </div>
                <div className="grid content-start gap-2">
                    <Label htmlFor={field('due')}>
                        {t('my_space.tasks.field.due_date')}
                    </Label>
                    <DatePicker
                        id={field('due')}
                        value={dueDate}
                        onChange={setDueDate}
                        invalid={Boolean(errors.due_date)}
                    />
                    <InputError message={errors.due_date} />
                </div>
            </div>

            {projects.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    {t('my_space.tasks.new.no_projects')}
                </p>
            ) : null}

            <DialogFooter className="gap-2">
                <Button
                    type="button"
                    variant="secondary"
                    disabled={processing}
                    onClick={onDone}
                >
                    {t('common.cancel')}
                </Button>
                <Button
                    type="submit"
                    disabled={processing}
                    data-test="my-space-task-save"
                >
                    {processing ? <Spinner /> : null}
                    {t('my_space.tasks.new.submit')}
                </Button>
            </DialogFooter>
        </form>
    );
}

/**
 * «Editar tarea» de «Mi espacio»: el título (la descripción del original), la prioridad y la entrega.
 * El proyecto no se cambia aquí (mover una tarea tiene sus reglas): se hace desde la tarea.
 */
export function EditTaskDialog({
    task,
    open,
    onOpenChange,
}: {
    task: MySpaceTask;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="sm:max-w-lg"
                data-test="my-space-task-edit"
            >
                {open ? (
                    <EditTaskForm
                        task={task}
                        onDone={() => onOpenChange(false)}
                    />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

function EditTaskForm({
    task,
    onDone,
}: {
    task: MySpaceTask;
    onDone: () => void;
}) {
    const id = useId();
    const [title, setTitle] = useState(task.title);
    const [priority, setPriority] = useState<TaskPriority>(task.priority);
    const [dueDate, setDueDate] = useState<string | null>(task.due_date);
    const [errors, setErrors] = useState<Errors>({});
    const [processing, setProcessing] = useState(false);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (title.trim() === '') {
            setErrors({ title: t('my_space.tasks.errors.title') });

            return;
        }

        router.patch(
            updateTask.url(task.id),
            { title: title.trim(), priority, due_date: dueDate },
            {
                preserveScroll: true,
                preserveState: true,
                only: MY_TASKS_RELOAD,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => {
                    toast.success(
                        t('my_space.tasks.toast.created', {
                            task: title.trim(),
                        }),
                    );
                    onDone();
                },
                onError: (server) =>
                    setErrors(serverErrors(server as Record<string, string>)),
            },
        );
    };

    return (
        <form onSubmit={submit} noValidate className="grid gap-4">
            <DialogHeader>
                <DialogTitle>{t('my_space.tasks.edit.title')}</DialogTitle>
                <DialogDescription>
                    {t('my_space.tasks.edit.description', {
                        project: `${task.project.code} · ${task.project.name}`,
                    })}
                </DialogDescription>
            </DialogHeader>

            {errors.general ? (
                <Alert variant="destructive" role="alert">
                    <CircleAlert aria-hidden="true" />
                    <AlertDescription>{errors.general}</AlertDescription>
                </Alert>
            ) : null}

            <div className="grid gap-2">
                <Label htmlFor={`${id}-title`}>
                    {t('my_space.tasks.field.title')}
                </Label>
                <Input
                    id={`${id}-title`}
                    value={title}
                    onChange={(event) => setTitle(event.target.value)}
                    maxLength={255}
                    autoComplete="off"
                    autoFocus
                    aria-invalid={errors.title ? true : undefined}
                />
                <InputError message={errors.title} />
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid content-start gap-2">
                    <Label htmlFor={`${id}-priority`}>
                        {t('my_space.tasks.field.priority')}
                    </Label>
                    <PrioritySelect
                        id={`${id}-priority`}
                        value={priority}
                        onChange={setPriority}
                    />
                </div>
                <div className="grid content-start gap-2">
                    <Label htmlFor={`${id}-due`}>
                        {t('my_space.tasks.field.due_date')}
                    </Label>
                    <DatePicker
                        id={`${id}-due`}
                        value={dueDate}
                        onChange={setDueDate}
                        invalid={Boolean(errors.due_date)}
                    />
                    <InputError message={errors.due_date} />
                </div>
            </div>
            <DialogFooter className="gap-2">
                <Button
                    type="button"
                    variant="secondary"
                    disabled={processing}
                    onClick={onDone}
                >
                    {t('common.cancel')}
                </Button>
                <Button type="submit" disabled={processing}>
                    {processing ? <Spinner /> : null}
                    {t('common.save')}
                </Button>
            </DialogFooter>
        </form>
    );
}
