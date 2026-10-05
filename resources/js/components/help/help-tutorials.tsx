import { router } from '@inertiajs/react';
import { ListOrdered, Pencil, Play, Plus, Trash2, Video } from 'lucide-react';
import type { ReactNode } from 'react';
import { useId, useRef, useState } from 'react';
import { Field } from '@/components/admin/field';
import { NativeSelect } from '@/components/admin/native-select';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { SortableList } from '@/components/help/sortable-list';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Progress } from '@/components/ui/progress';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatDate } from '@/lib/format';
import { formatMegabytes } from '@/lib/help-center';
import { t } from '@/lib/i18n';
import { uploadVideoInChunks, VideoUploadError } from '@/lib/video-upload';
import type { UploadProgress } from '@/lib/video-upload';
import {
    destroy as destroyTutorial,
    reorder as reorderTutorials,
    store as storeTutorial,
    update as updateTutorial,
} from '@/routes/help/tutorials';
import type { HelpReleaseOption, HelpTutorial } from '@/types/weeklies';

/**
 * Crear o editar un tutorial (F-155): título, descripción, la versión a la que va ligado y el vídeo
 * (hasta 200 MB), que sube por trozos con su progreso (D-207) antes de guardar. Al editar, sin vídeo
 * nuevo se conserva el que hay.
 */
export function TutorialDialog({
    tutorial,
    releases,
    maxBytes,
    trigger,
}: {
    tutorial: HelpTutorial | null;
    releases: HelpReleaseOption[];
    maxBytes: number;
    trigger: ReactNode;
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const [title, setTitle] = useState('');
    const [description, setDescription] = useState('');
    const [releaseId, setReleaseId] = useState('');
    const [file, setFile] = useState<File | null>(null);
    const [progress, setProgress] = useState<UploadProgress | null>(null);
    const [stage, setStage] = useState<'idle' | 'uploading' | 'saving'>('idle');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const abort = useRef<AbortController | null>(null);
    const busy = stage !== 'idle';

    const reset = () => {
        setTitle(tutorial?.title ?? '');
        setDescription(tutorial?.description ?? '');
        setReleaseId(
            tutorial
                ? String(tutorial.help_release_id ?? '')
                : String(
                      releases.find((release) => !release.is_hidden)?.id ?? '',
                  ),
        );
        setFile(null);
        setProgress(null);
        setStage('idle');
        setErrors({});
    };

    const save = async () => {
        if (title.trim() === '') {
            setErrors({ title: t('help.tutorials.title_required') });

            return;
        }

        if (!tutorial && !file) {
            setErrors({ upload: t('help.tutorials.video_required') });

            return;
        }

        let upload: string | null = null;

        if (file) {
            if (file.size > maxBytes) {
                setErrors({ upload: t('help.tutorials.too_big') });

                return;
            }

            abort.current = new AbortController();
            setStage('uploading');
            setErrors({});

            try {
                upload = await uploadVideoInChunks(file, {
                    onProgress: setProgress,
                    signal: abort.current.signal,
                });
            } catch (error) {
                setStage('idle');
                setProgress(null);

                if (error instanceof DOMException) {
                    return;
                }

                setErrors({
                    upload:
                        error instanceof VideoUploadError && error.userMessage
                            ? error.userMessage
                            : t('help.tutorials.upload_failed'),
                });

                return;
            }
        }

        setStage('saving');
        const payload = {
            title,
            description,
            help_release_id: releaseId === '' ? null : Number(releaseId),
            upload,
        };
        const options = {
            preserveScroll: true,
            onError: (next: Record<string, string>) => setErrors(next),
            onSuccess: () => setOpen(false),
            onFinish: () => setStage('idle'),
        };

        if (tutorial) {
            router.put(updateTutorial.url(tutorial.id), payload, options);
        } else {
            router.post(storeTutorial.url(), payload, options);
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next && busy) {
                    return;
                }

                setOpen(next);

                if (next) {
                    reset();
                }
            }}
        >
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="sm:max-w-lg">
                <form
                    className="grid gap-5"
                    noValidate
                    onSubmit={(event) => {
                        event.preventDefault();
                        void save();
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {tutorial
                                ? t('help.tutorials.edit_title')
                                : t('help.tutorials.create_title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('help.tutorials.dialog_description')}
                        </DialogDescription>
                    </DialogHeader>
                    <Field
                        id={`${id}-title`}
                        label={t('help.tutorials.title_field')}
                        error={errors.title}
                    >
                        <Input
                            id={`${id}-title`}
                            value={title}
                            maxLength={200}
                            disabled={busy}
                            onChange={(event) => setTitle(event.target.value)}
                            aria-invalid={Boolean(errors.title)}
                        />
                    </Field>
                    <Field
                        id={`${id}-description`}
                        label={t('help.tutorials.description')}
                        optional={t('weeklies.common.optional')}
                        error={errors.description}
                    >
                        <Textarea
                            id={`${id}-description`}
                            rows={3}
                            value={description}
                            disabled={busy}
                            onChange={(event) =>
                                setDescription(event.target.value)
                            }
                        />
                    </Field>
                    <Field
                        id={`${id}-release`}
                        label={t('help.tutorials.release')}
                        error={errors.help_release_id}
                    >
                        <NativeSelect
                            id={`${id}-release`}
                            value={releaseId}
                            disabled={busy}
                            onChange={(event) =>
                                setReleaseId(event.target.value)
                            }
                        >
                            <option value="">
                                {t('help.tutorials.no_release')}
                            </option>
                            {releases.map((release) => (
                                <option key={release.id} value={release.id}>
                                    {release.version}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                    <Field
                        id={`${id}-video`}
                        label={
                            tutorial
                                ? t('help.tutorials.replace_video')
                                : t('help.tutorials.video')
                        }
                        help={
                            tutorial
                                ? t('help.tutorials.replace_help', {
                                      name: tutorial.video_name ?? '—',
                                  })
                                : t('help.tutorials.video_help')
                        }
                        error={errors.upload}
                    >
                        <Input
                            id={`${id}-video`}
                            type="file"
                            accept="video/mp4,video/webm,video/quicktime,video/ogg,video/x-m4v,.mp4,.webm,.mov,.ogv,.m4v"
                            disabled={busy}
                            onChange={(event) => {
                                const next = event.target.files?.[0] ?? null;
                                setErrors({});

                                if (next && next.size > maxBytes) {
                                    setErrors({
                                        upload: t('help.tutorials.too_big'),
                                    });
                                    setFile(null);

                                    return;
                                }

                                setFile(next);
                            }}
                            aria-invalid={Boolean(errors.upload)}
                        />
                    </Field>
                    {file ? (
                        <p className="text-sm text-muted-foreground">
                            {t('help.tutorials.selected_size', {
                                size: formatMegabytes(file.size),
                                max: formatMegabytes(maxBytes),
                            })}
                        </p>
                    ) : null}
                    {progress ? (
                        <div className="grid gap-2" aria-live="polite">
                            <p className="text-sm font-medium">
                                {stage === 'saving'
                                    ? t('help.tutorials.saving')
                                    : t('help.tutorials.uploading')}
                            </p>
                            <Progress
                                value={progress.percentage}
                                aria-label={t('help.tutorials.progress')}
                            />
                            <p className="tabular text-xs text-muted-foreground">
                                {formatMegabytes(progress.uploadedBytes)} /{' '}
                                {formatMegabytes(progress.totalBytes)} ·{' '}
                                {progress.percentage} %
                            </p>
                        </div>
                    ) : null}
                    <DialogFooter className="gap-2">
                        {stage === 'uploading' ? (
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => abort.current?.abort()}
                            >
                                {t('help.tutorials.cancel_upload')}
                            </Button>
                        ) : (
                            <DialogClose asChild>
                                <Button
                                    type="button"
                                    variant="secondary"
                                    disabled={busy}
                                >
                                    {t('common.cancel')}
                                </Button>
                            </DialogClose>
                        )}
                        <Button type="submit" disabled={busy}>
                            {busy && <Spinner />}
                            {t('common.save')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function DeleteTutorial({ tutorial }: { tutorial: HelpTutorial }) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    return (
        <ConfirmDialog
            open={open}
            onOpenChange={setOpen}
            trigger={
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-8"
                    aria-label={t('help.tutorials.delete', {
                        title: tutorial.title,
                    })}
                >
                    <Trash2 aria-hidden="true" />
                </Button>
            }
            title={t('help.tutorials.delete_title')}
            description={t('help.tutorials.delete_description')}
            confirmLabel={t('common.delete')}
            processing={processing}
            onConfirm={() =>
                router.delete(destroyTutorial.url(tutorial.id), {
                    preserveScroll: true,
                    onStart: () => setProcessing(true),
                    onFinish: () => setProcessing(false),
                    onSuccess: () => setOpen(false),
                })
            }
        />
    );
}

/** Gestionar los tutoriales: reordenarlos arrastrando y editarlos o eliminarlos. */
function TutorialManager({
    tutorials,
    releases,
    maxBytes,
}: {
    tutorials: HelpTutorial[];
    releases: HelpReleaseOption[];
    maxBytes: number;
}) {
    const [order, setOrder] = useState(tutorials);
    const [source, setSource] = useState(tutorials);

    if (source !== tutorials) {
        setSource(tutorials);
        setOrder(tutorials);
    }

    return (
        <SortableList
            items={order}
            label={t('help.tutorials.manage_list')}
            titleOf={(tutorial) => tutorial.title}
            onReorder={(next) => {
                const previous = order;
                setOrder(next);
                router.put(
                    reorderTutorials.url(),
                    { ids: next.map((tutorial) => tutorial.id) },
                    {
                        preserveScroll: true,
                        onError: () => setOrder(previous),
                    },
                );
            }}
        >
            {(tutorial) => (
                <div className="flex items-center gap-2">
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-medium">
                            {tutorial.title}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {tutorial.version ?? t('help.tutorials.no_release')}
                        </p>
                    </div>
                    <TutorialDialog
                        tutorial={tutorial}
                        releases={releases}
                        maxBytes={maxBytes}
                        trigger={
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-8"
                                aria-label={t('help.tutorials.edit', {
                                    title: tutorial.title,
                                })}
                            >
                                <Pencil aria-hidden="true" />
                            </Button>
                        }
                    />
                    <DeleteTutorial tutorial={tutorial} />
                </div>
            )}
        </SortableList>
    );
}

/**
 * Pestaña Tutoriales (F-155): vídeos ligados a una versión, que se ven en un diálogo (el vídeo se
 * sirve a trozos, con Range, desde una URL firmada). Quien gestiona los añade, edita, sustituye,
 * elimina y reordena arrastrando.
 */
export function HelpTutorials({
    tutorials,
    releases,
    manage,
    maxBytes,
}: {
    tutorials: HelpTutorial[];
    releases: HelpReleaseOption[];
    manage: boolean;
    maxBytes: number;
}) {
    const id = useId();
    const [playing, setPlaying] = useState<HelpTutorial | null>(null);
    const [managing, setManaging] = useState(false);

    return (
        <section aria-labelledby={`${id}-title`} className="grid gap-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 id={`${id}-title`} className="text-base font-medium">
                        {t('help.tutorials.heading')}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {t('help.tutorials.intro')}
                    </p>
                </div>
                {manage ? (
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant="secondary"
                            disabled={tutorials.length === 0}
                            onClick={() => setManaging(true)}
                        >
                            <ListOrdered aria-hidden="true" />
                            {t('help.tutorials.manage')}
                        </Button>
                        <TutorialDialog
                            tutorial={null}
                            releases={releases}
                            maxBytes={maxBytes}
                            trigger={
                                <Button>
                                    <Plus aria-hidden="true" />
                                    {t('help.tutorials.add')}
                                </Button>
                            }
                        />
                    </div>
                ) : null}
            </div>
            {tutorials.length === 0 ? (
                <div className="grid justify-items-center gap-2 border px-4 py-10 text-center text-sm text-muted-foreground">
                    <Video aria-hidden="true" className="size-6" />
                    <p>
                        {manage
                            ? t('help.tutorials.empty_manage')
                            : t('help.tutorials.empty')}
                    </p>
                </div>
            ) : (
                <ul
                    className="grid gap-3 md:grid-cols-2"
                    data-test="help-tutorials"
                >
                    {tutorials.map((tutorial) => (
                        <li key={tutorial.id} className="grid gap-2 border p-4">
                            <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                <span className="border px-1.5 py-0.5">
                                    {tutorial.version ??
                                        t('help.tutorials.no_release')}
                                </span>
                                {tutorial.created_at ? (
                                    <span>
                                        {t('help.tutorials.uploaded_on', {
                                            date: formatDate(
                                                tutorial.created_at,
                                            ),
                                        })}
                                    </span>
                                ) : null}
                            </div>
                            <h3 className="font-medium">{tutorial.title}</h3>
                            {tutorial.description ? (
                                <p className="text-sm text-muted-foreground">
                                    {tutorial.description}
                                </p>
                            ) : null}
                            <div>
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    disabled={!tutorial.video_url}
                                    onClick={() => setPlaying(tutorial)}
                                    aria-label={t('help.tutorials.play', {
                                        title: tutorial.title,
                                    })}
                                >
                                    <Play aria-hidden="true" />
                                    {t('help.tutorials.watch')}
                                </Button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
            <Dialog
                open={playing !== null}
                onOpenChange={(open) => (open ? null : setPlaying(null))}
            >
                <DialogContent className="sm:max-w-4xl">
                    <DialogHeader>
                        <DialogTitle>{playing?.title}</DialogTitle>
                        <DialogDescription>
                            {[
                                playing?.version ??
                                    t('help.tutorials.no_release'),
                                playing?.description,
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                        </DialogDescription>
                    </DialogHeader>
                    {playing?.video_url ? (
                        <video
                            key={playing.id}
                            controls
                            preload="metadata"
                            src={playing.video_url}
                            className="aspect-video w-full bg-black"
                            data-test="help-video"
                        >
                            {t('help.tutorials.no_video_support')}
                        </video>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            {t('help.tutorials.no_video')}
                        </p>
                    )}
                </DialogContent>
            </Dialog>
            {manage ? (
                <Dialog open={managing} onOpenChange={setManaging}>
                    <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>
                                {t('help.tutorials.manage')}
                            </DialogTitle>
                            <DialogDescription>
                                {t('help.tutorials.manage_description')}
                            </DialogDescription>
                        </DialogHeader>
                        <TutorialManager
                            tutorials={tutorials}
                            releases={releases}
                            maxBytes={maxBytes}
                        />
                    </DialogContent>
                </Dialog>
            ) : null}
        </section>
    );
}
