import { router, useForm } from '@inertiajs/react';
import {
    ChevronDown,
    ListOrdered,
    MessageCircle,
    Pencil,
    Plus,
    Search,
    Settings2,
    Trash2,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useId, useMemo, useState } from 'react';
import { Field } from '@/components/admin/field';
import { NativeSelect } from '@/components/admin/native-select';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { SortableList } from '@/components/help/sortable-list';
import { RichTextContent } from '@/components/rich-text/rich-text-content';
import RichTextEditor from '@/components/rich-text/rich-text-editor';
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
import { Spinner } from '@/components/ui/spinner';
import { FOCUS_RING } from '@/lib/focus-ring';
import { searchFaqs } from '@/lib/help-center';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import {
    destroy as destroySection,
    reorder as reorderSections,
    store as storeSection,
    update as updateSection,
} from '@/routes/help/faq-sections';
import {
    destroy as destroyFaq,
    reorder as reorderFaqs,
    store as storeFaq,
    update as updateFaq,
} from '@/routes/help/faqs';
import type { HelpFaq, HelpFaqSection } from '@/types/weeklies';

function SectionDialog({
    section,
    trigger,
}: {
    section: HelpFaqSection | null;
    trigger: ReactNode;
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const form = useForm({ name: section?.name ?? '' });

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);

                if (next) {
                    form.setData({ name: section?.name ?? '' });
                    form.clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="sm:max-w-md">
                <form
                    className="grid gap-5"
                    noValidate
                    onSubmit={(event) => {
                        event.preventDefault();
                        const options = {
                            preserveScroll: true,
                            onSuccess: () => setOpen(false),
                        };

                        if (section) {
                            form.put(updateSection.url(section.id), options);
                        } else {
                            form.post(storeSection.url(), options);
                        }
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {section
                                ? t('help.faq.edit_section')
                                : t('help.faq.add_section')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('help.faq.section_description')}
                        </DialogDescription>
                    </DialogHeader>
                    <Field
                        id={`${id}-name`}
                        label={t('help.faq.section_name')}
                        error={form.errors.name}
                    >
                        <Input
                            id={`${id}-name`}
                            value={form.data.name}
                            maxLength={120}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            aria-invalid={Boolean(form.errors.name)}
                        />
                    </Field>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button
                                type="button"
                                variant="secondary"
                                disabled={form.processing}
                            >
                                {t('common.cancel')}
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {t('common.save')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function FaqDialog({
    faq,
    sections,
    defaultSection,
    trigger,
}: {
    faq: HelpFaq | null;
    sections: HelpFaqSection[];
    defaultSection: number | null;
    trigger: ReactNode;
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const initial = () => ({
        help_faq_section_id: String(
            faq?.help_faq_section_id ?? defaultSection ?? sections[0]?.id ?? '',
        ),
        question: faq?.question ?? '',
        answer: faq?.answer ?? '',
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
                        form.transform((data) => ({
                            ...data,
                            help_faq_section_id: Number(
                                data.help_faq_section_id,
                            ),
                        }));
                        const options = {
                            preserveScroll: true,
                            onSuccess: () => setOpen(false),
                        };

                        if (faq) {
                            form.put(updateFaq.url(faq.id), options);
                        } else {
                            form.post(storeFaq.url(), options);
                        }
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {faq
                                ? t('help.faq.edit_question')
                                : t('help.faq.add_question')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('help.faq.question_description')}
                        </DialogDescription>
                    </DialogHeader>
                    <Field
                        id={`${id}-section`}
                        label={t('help.faq.section')}
                        error={form.errors.help_faq_section_id}
                    >
                        <NativeSelect
                            id={`${id}-section`}
                            value={form.data.help_faq_section_id}
                            onChange={(event) =>
                                form.setData(
                                    'help_faq_section_id',
                                    event.target.value,
                                )
                            }
                        >
                            {sections.map((section) => (
                                <option key={section.id} value={section.id}>
                                    {section.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                    <Field
                        id={`${id}-question`}
                        label={t('help.faq.question')}
                        error={form.errors.question}
                    >
                        <Input
                            id={`${id}-question`}
                            value={form.data.question}
                            maxLength={500}
                            onChange={(event) =>
                                form.setData('question', event.target.value)
                            }
                            aria-invalid={Boolean(form.errors.question)}
                        />
                    </Field>
                    <div className="grid gap-2">
                        <span className="text-sm font-medium">
                            {t('help.faq.answer')}
                        </span>
                        <RichTextEditor
                            value={form.data.answer}
                            onChange={(html) => form.setData('answer', html)}
                            mentionables={[]}
                            aria-label={t('help.faq.answer')}
                        />
                        {form.errors.answer ? (
                            <p className="text-sm text-destructive">
                                {form.errors.answer}
                            </p>
                        ) : null}
                    </div>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button
                                type="button"
                                variant="secondary"
                                disabled={form.processing}
                            >
                                {t('common.cancel')}
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {t('common.save')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function DeleteButton({
    label,
    title,
    description,
    url,
}: {
    label: string;
    title: string;
    description: string;
    url: string;
}) {
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
                    aria-label={label}
                >
                    <Trash2 aria-hidden="true" />
                </Button>
            }
            title={title}
            description={description}
            confirmLabel={t('common.delete')}
            processing={processing}
            onConfirm={() =>
                router.delete(url, {
                    preserveScroll: true,
                    onStart: () => setProcessing(true),
                    onFinish: () => setProcessing(false),
                    onSuccess: () => setOpen(false),
                    onError: () => setOpen(false),
                })
            }
        />
    );
}

/** Reordenar de forma optimista: si el servidor lo rechaza, vuelve como estaba. */
function useOptimisticOrder<T>(items: T[]) {
    const [order, setOrder] = useState(items);
    const [source, setSource] = useState(items);

    if (source !== items) {
        setSource(items);
        setOrder(items);
    }

    return [order, setOrder] as const;
}

function SectionsManager({ sections }: { sections: HelpFaqSection[] }) {
    const [order, setOrder] = useOptimisticOrder(sections);

    return (
        <div className="grid gap-4">
            <SortableList
                items={order}
                label={t('help.faq.sections')}
                titleOf={(section) => section.name}
                onReorder={(next) => {
                    const previous = order;
                    setOrder(next);
                    router.put(
                        reorderSections.url(),
                        { ids: next.map((section) => section.id) },
                        {
                            preserveScroll: true,
                            onError: () => setOrder(previous),
                        },
                    );
                }}
            >
                {(section) => (
                    <div className="flex items-center gap-2">
                        <span className="min-w-0 flex-1 truncate text-sm">
                            {section.name}{' '}
                            <span className="text-muted-foreground">
                                ({section.faqs.length})
                            </span>
                        </span>
                        <SectionDialog
                            section={section}
                            trigger={
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="size-8"
                                    aria-label={t(
                                        'help.faq.edit_section_named',
                                        {
                                            name: section.name,
                                        },
                                    )}
                                >
                                    <Pencil aria-hidden="true" />
                                </Button>
                            }
                        />
                        <DeleteButton
                            label={t('help.faq.delete_section_named', {
                                name: section.name,
                            })}
                            title={t('help.faq.delete_section')}
                            description={t(
                                'help.faq.delete_section_description',
                            )}
                            url={destroySection.url(section.id)}
                        />
                    </div>
                )}
            </SortableList>
            <div>
                <SectionDialog
                    section={null}
                    trigger={
                        <Button variant="secondary">
                            <Plus aria-hidden="true" />
                            {t('help.faq.add_section')}
                        </Button>
                    }
                />
            </div>
        </div>
    );
}

function FaqItem({
    faq,
    sections,
    manage,
}: {
    faq: HelpFaq;
    sections: HelpFaqSection[];
    manage: boolean;
}) {
    const [open, setOpen] = useState(false);
    const panel = useId();

    return (
        <li className="border" data-test="help-faq">
            <div className="flex items-center gap-2">
                <button
                    type="button"
                    aria-expanded={open}
                    aria-controls={panel}
                    onClick={() => setOpen((current) => !current)}
                    className={cn(
                        'flex min-w-0 flex-1 items-center justify-between gap-3 px-4 py-3 text-left font-medium',
                        FOCUS_RING,
                    )}
                >
                    <span>{faq.question}</span>
                    <ChevronDown
                        aria-hidden="true"
                        className={cn(
                            'size-4 shrink-0 transition-transform',
                            open && 'rotate-180',
                        )}
                    />
                </button>
                {manage ? (
                    <div className="flex shrink-0 pr-2">
                        <FaqDialog
                            faq={faq}
                            sections={sections}
                            defaultSection={faq.help_faq_section_id}
                            trigger={
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="size-8"
                                    aria-label={t(
                                        'help.faq.edit_question_named',
                                        {
                                            question: faq.question,
                                        },
                                    )}
                                >
                                    <Pencil aria-hidden="true" />
                                </Button>
                            }
                        />
                        <DeleteButton
                            label={t('help.faq.delete_question_named', {
                                question: faq.question,
                            })}
                            title={t('help.faq.delete_question')}
                            description={t(
                                'help.faq.delete_question_description',
                            )}
                            url={destroyFaq.url(faq.id)}
                        />
                    </div>
                ) : null}
            </div>
            {open ? (
                <div id={panel} className="border-t px-4 py-3">
                    <RichTextContent html={faq.answer} />
                </div>
            ) : null}
        </li>
    );
}

function FaqReorder({ section }: { section: HelpFaqSection }) {
    const [order, setOrder] = useOptimisticOrder(section.faqs);

    return (
        <SortableList
            items={order}
            label={t('help.faq.reorder_list', { section: section.name })}
            titleOf={(faq) => faq.question}
            onReorder={(next) => {
                const previous = order;
                setOrder(next);
                router.put(
                    reorderFaqs.url(),
                    {
                        help_faq_section_id: section.id,
                        ids: next.map((faq) => faq.id),
                    },
                    {
                        preserveScroll: true,
                        onError: () => setOrder(previous),
                    },
                );
            }}
        >
            {(faq) => <span className="text-sm">{faq.question}</span>}
        </SortableList>
    );
}

function resultsLabel(sections: HelpFaqSection[]): string {
    const count = sections.reduce(
        (total, section) => total + section.faqs.length,
        0,
    );

    return count === 1
        ? t('help.faq.results_one')
        : t('help.faq.results', { count });
}

/**
 * Pestaña Preguntas frecuentes (F-156): secciones con su número de preguntas, el buscador (en las
 * preguntas y las respuestas de todas las secciones) y cada respuesta se despliega con su botón.
 * Quien gestiona crea, edita, elimina y reordena secciones y preguntas.
 */
export function HelpFaqTab({
    sections,
    manage,
}: {
    sections: HelpFaqSection[];
    manage: boolean;
}) {
    const id = useId();
    const [q, setQ] = useState('');
    const [activeId, setActiveId] = useState<number | null>(
        sections[0]?.id ?? null,
    );
    const [managing, setManaging] = useState(false);
    const [reordering, setReordering] = useState(false);
    const searching = q.trim() !== '';
    const results = useMemo(() => searchFaqs(sections, q), [sections, q]);
    const active =
        sections.find((section) => section.id === activeId) ??
        sections[0] ??
        null;
    const shown = searching ? results : active ? [active] : [];

    return (
        <div className="grid gap-4">
            <div className="flex flex-wrap items-center gap-2">
                <div className="relative min-w-0 flex-1">
                    <Search
                        aria-hidden="true"
                        className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        type="search"
                        value={q}
                        onChange={(event) => setQ(event.target.value)}
                        placeholder={t('help.faq.search_placeholder')}
                        aria-label={t('help.faq.search')}
                        className="pl-8"
                    />
                </div>
                {manage ? (
                    <>
                        <Button
                            variant="secondary"
                            onClick={() => setManaging(true)}
                        >
                            <Settings2 aria-hidden="true" />
                            {t('help.faq.manage_sections')}
                        </Button>
                        <FaqDialog
                            faq={null}
                            sections={sections}
                            defaultSection={active?.id ?? null}
                            trigger={
                                <Button disabled={sections.length === 0}>
                                    <Plus aria-hidden="true" />
                                    {t('help.faq.add_question')}
                                </Button>
                            }
                        />
                    </>
                ) : null}
            </div>
            <p className="sr-only" aria-live="polite">
                {searching ? resultsLabel(results) : ''}
            </p>
            {sections.length === 0 ? (
                <div className="grid justify-items-center gap-2 border px-4 py-10 text-center text-sm text-muted-foreground">
                    <MessageCircle aria-hidden="true" className="size-6" />
                    <p>
                        {manage
                            ? t('help.faq.empty_manage')
                            : t('help.faq.empty')}
                    </p>
                </div>
            ) : (
                <div className="grid gap-4 md:grid-cols-[14rem_1fr]">
                    <nav aria-labelledby={`${id}-sections`}>
                        <h2
                            id={`${id}-sections`}
                            className="mb-2 text-sm font-medium"
                        >
                            {t('help.faq.sections')}
                        </h2>
                        <ul className="grid gap-1">
                            {sections.map((section) => (
                                <li key={section.id}>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setActiveId(section.id);
                                            setQ('');
                                            setReordering(false);
                                        }}
                                        aria-current={
                                            !searching &&
                                            active?.id === section.id
                                                ? 'true'
                                                : undefined
                                        }
                                        className={cn(
                                            'flex w-full items-center justify-between gap-2 border px-3 py-2 text-left text-sm',
                                            !searching &&
                                                active?.id === section.id
                                                ? 'border-primary bg-accent'
                                                : 'hover:bg-accent',
                                            FOCUS_RING,
                                        )}
                                    >
                                        <span className="min-w-0 truncate">
                                            {section.name}
                                        </span>
                                        <span className="tabular text-muted-foreground">
                                            {section.faqs.length}
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </nav>
                    <div className="grid content-start gap-4">
                        {shown.length === 0 ? (
                            <p className="border px-4 py-8 text-center text-sm text-muted-foreground">
                                {searching
                                    ? t('help.faq.no_results')
                                    : t('help.faq.section_empty')}
                            </p>
                        ) : null}
                        {shown.map((section) => (
                            <section
                                key={section.id}
                                aria-labelledby={`${id}-s-${section.id}`}
                                className="grid gap-2"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <h2
                                        id={`${id}-s-${section.id}`}
                                        className="text-base font-medium"
                                    >
                                        {section.name}
                                    </h2>
                                    {manage &&
                                    !searching &&
                                    section.faqs.length > 1 ? (
                                        <Button
                                            variant="secondary"
                                            size="sm"
                                            aria-pressed={reordering}
                                            onClick={() =>
                                                setReordering(
                                                    (current) => !current,
                                                )
                                            }
                                        >
                                            <ListOrdered aria-hidden="true" />
                                            {reordering
                                                ? t('help.faq.reorder_done')
                                                : t('help.faq.reorder')}
                                        </Button>
                                    ) : null}
                                </div>
                                {section.faqs.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        {t('help.faq.section_empty')}
                                    </p>
                                ) : reordering && !searching ? (
                                    <FaqReorder section={section} />
                                ) : (
                                    <ul className="grid gap-2">
                                        {section.faqs.map((faq) => (
                                            <FaqItem
                                                key={faq.id}
                                                faq={faq}
                                                sections={sections}
                                                manage={manage}
                                            />
                                        ))}
                                    </ul>
                                )}
                            </section>
                        ))}
                    </div>
                </div>
            )}
            {manage ? (
                <Dialog open={managing} onOpenChange={setManaging}>
                    <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                        <DialogHeader>
                            <DialogTitle>
                                {t('help.faq.manage_sections')}
                            </DialogTitle>
                            <DialogDescription>
                                {t('help.faq.manage_description')}
                            </DialogDescription>
                        </DialogHeader>
                        <SectionsManager sections={sections} />
                    </DialogContent>
                </Dialog>
            ) : null}
        </div>
    );
}
