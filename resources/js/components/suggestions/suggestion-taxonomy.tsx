import { router, useForm } from '@inertiajs/react';
import { EyeOff, Pencil, Plus, Trash2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { useId, useState } from 'react';
import { Field } from '@/components/admin/field';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { SortableList } from '@/components/help/sortable-list';
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
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import {
    destroy as destroyBoard,
    reorder as reorderBoards,
    store as storeBoard,
    update as updateBoard,
} from '@/routes/suggestions/boards';
import {
    destroy as destroyCategory,
    reorder as reorderCategories,
    store as storeCategory,
    update as updateCategory,
} from '@/routes/suggestions/categories';
import type { SuggestionBoard, SuggestionCategory } from '@/types/weeklies';

const BUGS_SLUG = 'bugs';

/** Crear o editar un tablero o una categoría: nombre, slug, descripción y si se ve. */
function ItemDialog({
    kind,
    item,
    board,
    trigger,
}: {
    kind: 'board' | 'category';
    item: SuggestionBoard | SuggestionCategory | null;
    board: SuggestionBoard | null;
    trigger: ReactNode;
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const initial = () => ({
        name: item?.name ?? '',
        slug: item?.slug ?? '',
        description: item?.description ?? '',
        is_active: item?.is_active ?? true,
    });
    const form = useForm(initial());
    const lockedSlug = kind === 'category' && item?.slug === BUGS_SLUG;

    const submit = () => {
        const options = {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        };

        if (kind === 'board') {
            if (item) {
                form.put(updateBoard.url(item.id), options);
            } else {
                form.post(storeBoard.url(), options);
            }

            return;
        }

        if (item) {
            form.put(updateCategory.url(item.id), options);
        } else if (board) {
            form.post(storeCategory.url(board.id), options);
        }
    };

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
            <DialogContent className="sm:max-w-md">
                <form
                    className="grid gap-4"
                    noValidate
                    onSubmit={(event) => {
                        event.preventDefault();
                        submit();
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {t(
                                `suggestions.taxonomy.${kind}_${item ? 'edit' : 'create'}`,
                            )}
                        </DialogTitle>
                        <DialogDescription>
                            {t(`suggestions.taxonomy.${kind}_description`)}
                        </DialogDescription>
                    </DialogHeader>
                    <Field
                        id={`${id}-name`}
                        label={t('suggestions.taxonomy.name')}
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
                    <Field
                        id={`${id}-slug`}
                        label={t('suggestions.taxonomy.slug')}
                        optional={t('weeklies.common.optional')}
                        help={
                            lockedSlug
                                ? t('suggestions.taxonomy.bugs_slug')
                                : t('suggestions.taxonomy.slug_help')
                        }
                        error={form.errors.slug}
                    >
                        <Input
                            id={`${id}-slug`}
                            value={form.data.slug}
                            maxLength={80}
                            disabled={lockedSlug}
                            onChange={(event) =>
                                form.setData('slug', event.target.value)
                            }
                        />
                    </Field>
                    <Field
                        id={`${id}-description`}
                        label={t('suggestions.taxonomy.description')}
                        optional={t('weeklies.common.optional')}
                        error={form.errors.description}
                    >
                        <Textarea
                            id={`${id}-description`}
                            rows={2}
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                        />
                    </Field>
                    <div className="flex items-center gap-2">
                        <Checkbox
                            id={`${id}-active`}
                            checked={form.data.is_active}
                            onCheckedChange={(checked) =>
                                form.setData('is_active', checked === true)
                            }
                        />
                        <Label htmlFor={`${id}-active`}>
                            {t('suggestions.taxonomy.visible')}
                        </Label>
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

function DeleteItem({
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

function useOptimistic<T>(items: T[]) {
    const [order, setOrder] = useState(items);
    const [source, setSource] = useState(items);

    if (source !== items) {
        setSource(items);
        setOrder(items);
    }

    return [order, setOrder] as const;
}

function HiddenMark({ active }: { active: boolean }) {
    return active ? null : (
        <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
            <EyeOff aria-hidden="true" className="size-3.5" />
            {t('suggestions.taxonomy.hidden')}
        </span>
    );
}

/**
 * «Gestionar categorías» (F-160): los tableros y, del elegido, sus categorías; crear, editar, ocultar,
 * eliminar y reordenar arrastrando. Solo quien gestiona. La categoría «Bugs» (F-169) no se elimina.
 */
export function SuggestionTaxonomy({
    boards,
    open,
    onOpenChange,
}: {
    boards: SuggestionBoard[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [boardOrder, setBoardOrder] = useOptimistic(boards);
    const [selected, setSelected] = useState<number | null>(
        boards[0]?.id ?? null,
    );
    const board =
        boards.find((item) => item.id === selected) ?? boards[0] ?? null;
    const [categoryOrder, setCategoryOrder] = useOptimistic(
        board?.categories ?? [],
    );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>{t('suggestions.taxonomy.title')}</DialogTitle>
                    <DialogDescription>
                        {t('suggestions.taxonomy.intro')}
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-6 md:grid-cols-2">
                    <section className="grid content-start gap-3">
                        <div className="flex items-center justify-between gap-2">
                            <h3 className="text-sm font-medium">
                                {t('suggestions.taxonomy.boards')}
                            </h3>
                            <ItemDialog
                                kind="board"
                                item={null}
                                board={null}
                                trigger={
                                    <Button variant="secondary" size="sm">
                                        <Plus aria-hidden="true" />
                                        {t('suggestions.taxonomy.add_board')}
                                    </Button>
                                }
                            />
                        </div>
                        <SortableList
                            items={boardOrder}
                            label={t('suggestions.taxonomy.boards')}
                            titleOf={(item) => item.name}
                            onReorder={(next) => {
                                const previous = boardOrder;
                                setBoardOrder(next);
                                router.put(
                                    reorderBoards.url(),
                                    { ids: next.map((item) => item.id) },
                                    {
                                        preserveScroll: true,
                                        onError: () => setBoardOrder(previous),
                                    },
                                );
                            }}
                        >
                            {(item) => (
                                <div className="flex items-center gap-1">
                                    <button
                                        type="button"
                                        onClick={() => setSelected(item.id)}
                                        aria-pressed={board?.id === item.id}
                                        className={cn(
                                            'min-w-0 flex-1 truncate px-1 text-left text-sm',
                                            board?.id === item.id &&
                                                'font-medium',
                                        )}
                                    >
                                        {item.name}{' '}
                                        <span className="text-muted-foreground">
                                            ({item.post_count})
                                        </span>
                                    </button>
                                    <HiddenMark active={item.is_active} />
                                    <ItemDialog
                                        kind="board"
                                        item={item}
                                        board={null}
                                        trigger={
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="size-8"
                                                aria-label={t(
                                                    'suggestions.taxonomy.edit_named',
                                                    { name: item.name },
                                                )}
                                            >
                                                <Pencil aria-hidden="true" />
                                            </Button>
                                        }
                                    />
                                    <DeleteItem
                                        label={t(
                                            'suggestions.taxonomy.delete_named',
                                            { name: item.name },
                                        )}
                                        title={t(
                                            'suggestions.taxonomy.delete_board',
                                        )}
                                        description={t(
                                            'suggestions.taxonomy.delete_board_description',
                                        )}
                                        url={destroyBoard.url(item.id)}
                                    />
                                </div>
                            )}
                        </SortableList>
                    </section>
                    <section className="grid content-start gap-3">
                        <div className="flex items-center justify-between gap-2">
                            <h3 className="text-sm font-medium">
                                {board
                                    ? t('suggestions.taxonomy.categories_of', {
                                          board: board.name,
                                      })
                                    : t('suggestions.categories')}
                            </h3>
                            {board ? (
                                <ItemDialog
                                    kind="category"
                                    item={null}
                                    board={board}
                                    trigger={
                                        <Button variant="secondary" size="sm">
                                            <Plus aria-hidden="true" />
                                            {t(
                                                'suggestions.taxonomy.add_category',
                                            )}
                                        </Button>
                                    }
                                />
                            ) : null}
                        </div>
                        {board && categoryOrder.length > 0 ? (
                            <SortableList
                                items={categoryOrder}
                                label={t('suggestions.taxonomy.categories_of', {
                                    board: board.name,
                                })}
                                titleOf={(item) => item.name}
                                onReorder={(next) => {
                                    const previous = categoryOrder;
                                    setCategoryOrder(next);
                                    router.put(
                                        reorderCategories.url(board.id),
                                        { ids: next.map((item) => item.id) },
                                        {
                                            preserveScroll: true,
                                            onError: () =>
                                                setCategoryOrder(previous),
                                        },
                                    );
                                }}
                            >
                                {(item) => (
                                    <div className="flex items-center gap-1">
                                        <span className="min-w-0 flex-1 truncate px-1 text-sm">
                                            {item.name}{' '}
                                            <span className="text-muted-foreground">
                                                ({item.post_count})
                                            </span>
                                        </span>
                                        <HiddenMark active={item.is_active} />
                                        <ItemDialog
                                            kind="category"
                                            item={item}
                                            board={board}
                                            trigger={
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8"
                                                    aria-label={t(
                                                        'suggestions.taxonomy.edit_named',
                                                        { name: item.name },
                                                    )}
                                                >
                                                    <Pencil aria-hidden="true" />
                                                </Button>
                                            }
                                        />
                                        {item.slug !== BUGS_SLUG ? (
                                            <DeleteItem
                                                label={t(
                                                    'suggestions.taxonomy.delete_named',
                                                    { name: item.name },
                                                )}
                                                title={t(
                                                    'suggestions.taxonomy.delete_category',
                                                )}
                                                description={t(
                                                    'suggestions.taxonomy.delete_category_description',
                                                )}
                                                url={destroyCategory.url(
                                                    item.id,
                                                )}
                                            />
                                        ) : null}
                                    </div>
                                )}
                            </SortableList>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                {t('suggestions.taxonomy.no_categories')}
                            </p>
                        )}
                    </section>
                </div>
            </DialogContent>
        </Dialog>
    );
}
