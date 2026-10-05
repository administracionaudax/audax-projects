import { router, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { useId, useState } from 'react';
import { Field } from '@/components/admin/field';
import { NativeSelect } from '@/components/admin/native-select';
import { DatePicker } from '@/components/domain/date-picker';
import RichTextEditor from '@/components/rich-text/rich-text-editor';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatMegabytes, monthLabel, moveItem } from '@/lib/help-center';
import { t } from '@/lib/i18n';
import { todayInMadrid } from '@/lib/week';
import { update as updateSettings } from '@/routes/help/settings';
import {
    store as storeRelease,
    update as updateRelease,
} from '@/routes/help/releases';
import {
    store as storeUpdate,
    update as updateUpdate,
} from '@/routes/help/updates';
import type {
    HelpManualUpdate,
    HelpRelease,
    HelpSettings,
} from '@/types/weeklies';

/**
 * Diálogos del contenido de la pestaña General (solo quien gestiona, F-158): el manual y el enlace de
 * soporte (F-157), una versión con sus cambios en orden (F-150) y una actualización a mano (F-151).
 */

function FooterButtons({ processing }: { processing: boolean }) {
    return (
        <DialogFooter className="gap-2">
            <DialogClose asChild>
                <Button type="button" variant="secondary" disabled={processing}>
                    {t('common.cancel')}
                </Button>
            </DialogClose>
            <Button type="submit" disabled={processing}>
                {processing && <Spinner />}
                {t('common.save')}
            </Button>
        </DialogFooter>
    );
}

export function HelpSettingsDialog({
    settings,
    maxMegabytes,
    trigger,
}: {
    settings: HelpSettings;
    maxMegabytes: number;
    trigger: ReactNode;
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const form = useForm<{
        support_url: string;
        manual: File | null;
        remove_manual: boolean;
    }>({
        support_url: settings.support_url ?? '',
        manual: null,
        remove_manual: false,
    });

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);

                if (next) {
                    form.setData({
                        support_url: settings.support_url ?? '',
                        manual: null,
                        remove_manual: false,
                    });
                    form.clearErrors();
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
                        // Con un fichero, PUT viaja como POST con _method (multipart).
                        form.transform((data) => ({ ...data, _method: 'put' }));
                        form.post(updateSettings.url(), {
                            forceFormData: true,
                            preserveScroll: true,
                            onSuccess: () => setOpen(false),
                        });
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>{t('help.settings.title')}</DialogTitle>
                        <DialogDescription>
                            {t('help.settings.description')}
                        </DialogDescription>
                    </DialogHeader>
                    <Field
                        id={`${id}-url`}
                        label={t('help.settings.support_url')}
                        optional={t('weeklies.common.optional')}
                        error={form.errors.support_url}
                    >
                        <Input
                            id={`${id}-url`}
                            type="url"
                            inputMode="url"
                            placeholder="https://"
                            value={form.data.support_url}
                            onChange={(event) =>
                                form.setData('support_url', event.target.value)
                            }
                            aria-invalid={Boolean(form.errors.support_url)}
                        />
                    </Field>
                    <Field
                        id={`${id}-manual`}
                        label={t('help.settings.manual')}
                        help={t('help.settings.manual_help', {
                            max: maxMegabytes,
                        })}
                        error={form.errors.manual}
                    >
                        <Input
                            id={`${id}-manual`}
                            type="file"
                            accept="application/pdf,.pdf"
                            onChange={(event) =>
                                form.setData(
                                    'manual',
                                    event.target.files?.[0] ?? null,
                                )
                            }
                            aria-invalid={Boolean(form.errors.manual)}
                        />
                    </Field>
                    {settings.manual ? (
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id={`${id}-remove`}
                                checked={form.data.remove_manual}
                                onCheckedChange={(checked) =>
                                    form.setData(
                                        'remove_manual',
                                        checked === true,
                                    )
                                }
                            />
                            <Label htmlFor={`${id}-remove`}>
                                {t('help.settings.remove_manual', {
                                    name: settings.manual.name,
                                    size: formatMegabytes(settings.manual.size),
                                })}
                            </Label>
                        </div>
                    ) : null}
                    <FooterButtons processing={form.processing} />
                </form>
            </DialogContent>
        </Dialog>
    );
}

type ChangeDraft = { key: string; id: number | null; description: string };

let draftKey = 0;

function nextKey(): string {
    draftKey += 1;

    return `new-${draftKey}`;
}

/**
 * Crear o editar una versión (F-150): serie, mes, semana del mes, resumen y la lista de cambios,
 * que se reordena con «Subir» y «Bajar». Al guardar, los cambios van en ese orden.
 */
export function ReleaseDialog({
    release,
    trigger,
    defaults,
}: {
    release: HelpRelease | null;
    trigger: ReactNode;
    defaults?: { major_version: number; month_number: number };
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const initial = () => ({
        major_version: String(
            release?.major_version ?? defaults?.major_version ?? 1,
        ),
        month_number: String(
            release?.month_number ?? defaults?.month_number ?? 1,
        ),
        week_of_month: String(release?.week_of_month ?? 1),
        summary: release?.summary ?? '',
        is_hidden: release?.is_hidden ?? false,
        changes: (release?.changes ?? []).map((change) => ({
            key: `c-${change.id}`,
            id: change.id,
            description: change.description,
        })) as ChangeDraft[],
    });
    const [data, setData] = useState(initial);

    const setChange = (index: number, description: string) =>
        setData((current) => ({
            ...current,
            changes: current.changes.map((change, i) =>
                i === index ? { ...change, description } : change,
            ),
        }));

    const submit = () => {
        const payload = {
            major_version: Number(data.major_version),
            month_number: Number(data.month_number),
            week_of_month: Number(data.week_of_month),
            summary: data.summary,
            is_hidden: data.is_hidden,
            changes: data.changes
                .filter((change) => change.description.trim() !== '')
                .map((change) => ({
                    id: change.id,
                    description: change.description,
                })),
        };
        const options = {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onError: (next: Record<string, string>) => setErrors(next),
            onSuccess: () => setOpen(false),
        };

        if (release) {
            router.put(updateRelease.url(release.id), payload, options);
        } else {
            router.post(storeRelease.url(), payload, options);
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);

                if (next) {
                    setData(initial());
                    setErrors({});
                }
            }}
        >
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <form
                    className="grid gap-5"
                    noValidate
                    onSubmit={(event) => {
                        event.preventDefault();
                        submit();
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {release
                                ? t('help.releases.edit_title', {
                                      version: release.version,
                                  })
                                : t('help.releases.create_title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('help.releases.dialog_description')}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Field
                            id={`${id}-major`}
                            label={t('help.releases.major')}
                            error={errors.major_version}
                        >
                            <Input
                                id={`${id}-major`}
                                type="number"
                                min={1}
                                value={data.major_version}
                                onChange={(event) =>
                                    setData((current) => ({
                                        ...current,
                                        major_version: event.target.value,
                                    }))
                                }
                            />
                        </Field>
                        <Field
                            id={`${id}-month`}
                            label={t('help.releases.month')}
                            error={errors.month_number}
                        >
                            <NativeSelect
                                id={`${id}-month`}
                                value={data.month_number}
                                onChange={(event) =>
                                    setData((current) => ({
                                        ...current,
                                        month_number: event.target.value,
                                    }))
                                }
                            >
                                {Array.from({ length: 12 }, (_, i) => (
                                    <option key={i + 1} value={i + 1}>
                                        {monthLabel(i + 1)}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <Field
                            id={`${id}-week`}
                            label={t('help.releases.week')}
                            error={errors.week_of_month}
                        >
                            <NativeSelect
                                id={`${id}-week`}
                                value={data.week_of_month}
                                onChange={(event) =>
                                    setData((current) => ({
                                        ...current,
                                        week_of_month: event.target.value,
                                    }))
                                }
                            >
                                {[1, 2, 3, 4, 5].map((week) => (
                                    <option key={week} value={week}>
                                        {t('help.releases.week_option', {
                                            week,
                                        })}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                    </div>
                    <Field
                        id={`${id}-summary`}
                        label={t('help.releases.summary')}
                        optional={t('weeklies.common.optional')}
                        help={t('help.releases.summary_help')}
                        error={errors.summary}
                    >
                        <Textarea
                            id={`${id}-summary`}
                            rows={3}
                            value={data.summary}
                            onChange={(event) =>
                                setData((current) => ({
                                    ...current,
                                    summary: event.target.value,
                                }))
                            }
                        />
                    </Field>
                    <fieldset className="grid gap-2">
                        <legend className="mb-2 text-sm font-medium">
                            {t('help.releases.changes')}
                        </legend>
                        {data.changes.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('help.releases.no_changes')}
                            </p>
                        ) : (
                            <ol className="grid gap-2">
                                {data.changes.map((change, index) => (
                                    <li
                                        key={change.key}
                                        className="flex items-start gap-2"
                                    >
                                        <span className="tabular w-6 pt-2 text-right text-sm text-muted-foreground">
                                            {index + 1}.
                                        </span>
                                        <Textarea
                                            rows={2}
                                            value={change.description}
                                            onChange={(event) =>
                                                setChange(
                                                    index,
                                                    event.target.value,
                                                )
                                            }
                                            aria-label={t(
                                                'help.releases.change_label',
                                                { position: index + 1 },
                                            )}
                                            className="min-h-0 flex-1"
                                        />
                                        <div className="flex flex-col">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                className="size-8"
                                                disabled={index === 0}
                                                onClick={() =>
                                                    setData((current) => ({
                                                        ...current,
                                                        changes: moveItem(
                                                            current.changes,
                                                            index,
                                                            index - 1,
                                                        ),
                                                    }))
                                                }
                                                aria-label={t(
                                                    'help.releases.change_up',
                                                    { position: index + 1 },
                                                )}
                                            >
                                                <ArrowUp aria-hidden="true" />
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                className="size-8"
                                                disabled={
                                                    index ===
                                                    data.changes.length - 1
                                                }
                                                onClick={() =>
                                                    setData((current) => ({
                                                        ...current,
                                                        changes: moveItem(
                                                            current.changes,
                                                            index,
                                                            index + 1,
                                                        ),
                                                    }))
                                                }
                                                aria-label={t(
                                                    'help.releases.change_down',
                                                    { position: index + 1 },
                                                )}
                                            >
                                                <ArrowDown aria-hidden="true" />
                                            </Button>
                                        </div>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="size-8"
                                            onClick={() =>
                                                setData((current) => ({
                                                    ...current,
                                                    changes:
                                                        current.changes.filter(
                                                            (_, i) =>
                                                                i !== index,
                                                        ),
                                                }))
                                            }
                                            aria-label={t(
                                                'help.releases.change_remove',
                                                { position: index + 1 },
                                            )}
                                        >
                                            <Trash2 aria-hidden="true" />
                                        </Button>
                                    </li>
                                ))}
                            </ol>
                        )}
                        <div>
                            <Button
                                type="button"
                                variant="secondary"
                                size="sm"
                                onClick={() =>
                                    setData((current) => ({
                                        ...current,
                                        changes: [
                                            ...current.changes,
                                            {
                                                key: nextKey(),
                                                id: null,
                                                description: '',
                                            },
                                        ],
                                    }))
                                }
                            >
                                <Plus aria-hidden="true" />
                                {t('help.releases.add_change')}
                            </Button>
                        </div>
                        {Object.entries(errors)
                            .filter(([key]) => key.startsWith('changes'))
                            .slice(0, 1)
                            .map(([key, message]) => (
                                <p
                                    key={key}
                                    className="text-sm text-destructive"
                                >
                                    {message}
                                </p>
                            ))}
                    </fieldset>
                    {release?.is_hidden ? (
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id={`${id}-hidden`}
                                checked={!data.is_hidden}
                                onCheckedChange={(checked) =>
                                    setData((current) => ({
                                        ...current,
                                        is_hidden: checked !== true,
                                    }))
                                }
                            />
                            <Label htmlFor={`${id}-hidden`}>
                                {t('help.releases.show_again')}
                            </Label>
                        </div>
                    ) : null}
                    <FooterButtons processing={processing} />
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** Crear o editar una actualización a mano (F-151), con el contenido en texto con formato. */
export function ManualUpdateDialog({
    update,
    trigger,
}: {
    update: HelpManualUpdate | null;
    trigger: ReactNode;
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const initial = () => ({
        published_on: update?.published_on ?? todayInMadrid(),
        title: update?.title ?? '',
        subtitle: update?.subtitle ?? '',
        body: update?.body ?? '',
    });
    const form = useForm(initial());

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);

                if (next) {
                    form.setData(initial());
                    form.clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <form
                    className="grid gap-5"
                    noValidate
                    onSubmit={(event) => {
                        event.preventDefault();
                        const options = {
                            preserveScroll: true,
                            onSuccess: () => setOpen(false),
                        };

                        if (update) {
                            form.put(updateUpdate.url(update.id), options);
                        } else {
                            form.post(storeUpdate.url(), options);
                        }
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {update
                                ? t('help.updates.edit_title')
                                : t('help.updates.create_title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('help.updates.dialog_description')}
                        </DialogDescription>
                    </DialogHeader>
                    <Field
                        id={`${id}-date`}
                        label={t('help.updates.date')}
                        error={form.errors.published_on}
                    >
                        <DatePicker
                            id={`${id}-date`}
                            value={form.data.published_on}
                            onChange={(value) =>
                                form.setData('published_on', value ?? '')
                            }
                            clearable={false}
                            invalid={Boolean(form.errors.published_on)}
                        />
                    </Field>
                    <Field
                        id={`${id}-title`}
                        label={t('help.updates.title_field')}
                        error={form.errors.title}
                    >
                        <Input
                            id={`${id}-title`}
                            value={form.data.title}
                            maxLength={200}
                            placeholder={t('help.updates.title_placeholder')}
                            onChange={(event) =>
                                form.setData('title', event.target.value)
                            }
                            aria-invalid={Boolean(form.errors.title)}
                        />
                    </Field>
                    <Field
                        id={`${id}-subtitle`}
                        label={t('help.updates.subtitle')}
                        error={form.errors.subtitle}
                    >
                        <Textarea
                            id={`${id}-subtitle`}
                            rows={2}
                            maxLength={255}
                            value={form.data.subtitle}
                            placeholder={t('help.updates.subtitle_placeholder')}
                            onChange={(event) =>
                                form.setData('subtitle', event.target.value)
                            }
                            aria-invalid={Boolean(form.errors.subtitle)}
                        />
                    </Field>
                    <div className="grid gap-2">
                        <span className="text-sm font-medium">
                            {t('help.updates.body')}
                        </span>
                        <RichTextEditor
                            value={form.data.body}
                            onChange={(html) => form.setData('body', html)}
                            mentionables={[]}
                            aria-label={t('help.updates.body')}
                            placeholder={t('help.updates.body_placeholder')}
                        />
                        {form.errors.body ? (
                            <p className="text-sm text-destructive">
                                {form.errors.body}
                            </p>
                        ) : null}
                    </div>
                    <FooterButtons processing={form.processing} />
                </form>
            </DialogContent>
        </Dialog>
    );
}
