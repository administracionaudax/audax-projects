import { Head, router } from '@inertiajs/react';
import { BookCheck, CircleCheck, PenLine, Users } from 'lucide-react';
import { useId, useState } from 'react';
import InputError from '@/components/input-error';
import { PeopleFrame } from '@/components/people/people-ui';
import { SafeMarkdown } from '@/components/privacy/safe-markdown';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatDate, formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { read, update } from '@/routes/people/documents';
import type {
    DocumentsPageProps,
    PeopleDocumentData,
} from '@/types/people-register';

/**
 * «Documentos» (`/personas/documentos`, PLAN-FASE-11 §6.1.7; D-354; W-088 y W-108): el documento de
 * implantación del registro de jornada y la política de desconexión digital, con «He leído»
 * (queda la persona, la versión y cuándo). RR. HH. publica versiones nuevas y ve quién ha leído la
 * vigente. Mientras sean el borrador de la app, van marcados «pendiente de asesor».
 */
export default function DocumentsPage(props: DocumentsPageProps) {
    return (
        <>
            <Head title={t('people.documents.title')} />
            <PeopleFrame
                section="documents"
                title={t('people.documents.title')}
                description={t('people.documents.description')}
            >
                {props.documents.map((document) => (
                    <DocumentCard
                        key={document.id}
                        document={document}
                        canPublish={props.can_publish}
                        maxLength={props.max_length}
                    />
                ))}
            </PeopleFrame>
        </>
    );
}

function DocumentCard({
    document,
    canPublish,
    maxLength,
}: {
    document: PeopleDocumentData;
    canPublish: boolean;
    maxLength: number;
}) {
    const [processing, setProcessing] = useState(false);
    const [editing, setEditing] = useState(false);
    const readers = document.readers ?? [];
    const pending = readers.filter((reader) => reader.read_at === null);

    return (
        <article
            aria-labelledby={`document-${document.id}`}
            className="grid gap-4 rounded-md border p-4 md:p-6"
            data-test="people-document"
            data-key={document.key}
        >
            <header className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0 space-y-1">
                    <h2 id={`document-${document.id}`} className="text-xl">
                        {document.title}
                    </h2>
                    <p className="text-xs text-muted-foreground">
                        {document.is_draft
                            ? t('people.documents.draft_version', {
                                  version: document.version,
                              })
                            : t('people.documents.published_version', {
                                  version: document.version,
                                  date: formatDate(document.published_at),
                                  name: document.published_by ?? '',
                              })}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {document.is_draft ? (
                        <span className="rounded-md bg-warning-soft px-1.5 py-0.5 text-xs">
                            {t('people.documents.pending_advisor')}
                        </span>
                    ) : null}
                    {canPublish ? (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => setEditing(true)}
                            data-test="document-edit"
                        >
                            <PenLine aria-hidden="true" />
                            {t('people.documents.edit')}
                        </Button>
                    ) : null}
                </div>
            </header>

            <SafeMarkdown
                source={document.body}
                headingLevel={3}
                className="max-w-3xl"
            />

            <footer className="flex flex-wrap items-center gap-3 border-t pt-4">
                {document.read_at ? (
                    <p
                        className="flex items-center gap-2 text-sm"
                        data-test="document-read-at"
                    >
                        <CircleCheck
                            aria-hidden="true"
                            className="size-4 text-success"
                        />
                        {t('people.documents.read_on', {
                            version: document.version,
                            date: formatDateTime(document.read_at),
                        })}
                    </p>
                ) : (
                    <Button
                        type="button"
                        disabled={processing}
                        onClick={() =>
                            router.post(
                                read.url(document.id),
                                {},
                                {
                                    preserveScroll: true,
                                    onStart: () => setProcessing(true),
                                    onFinish: () => setProcessing(false),
                                },
                            )
                        }
                        data-test="document-read"
                    >
                        {processing ? (
                            <Spinner />
                        ) : (
                            <BookCheck aria-hidden="true" />
                        )}
                        {t('people.documents.mark_read')}
                    </Button>
                )}
            </footer>

            {document.readers ? (
                <details
                    className="rounded-md bg-muted/40 p-3 text-sm"
                    data-test="document-readers"
                >
                    <summary className="flex cursor-pointer items-center gap-2">
                        <Users aria-hidden="true" className="size-4" />
                        {t('people.documents.readers_summary', {
                            read: readers.length - pending.length,
                            total: readers.length,
                        })}
                    </summary>
                    <ul className="mt-2 grid gap-1 sm:grid-cols-2">
                        {readers.map((reader) => (
                            <li
                                key={reader.id}
                                className="flex justify-between gap-2"
                            >
                                <span>{reader.name}</span>
                                <span className="text-xs text-muted-foreground">
                                    {reader.read_at
                                        ? formatDate(reader.read_at)
                                        : t('people.documents.not_read')}
                                </span>
                            </li>
                        ))}
                    </ul>
                </details>
            ) : null}

            {canPublish ? (
                <EditDialog
                    document={document}
                    open={editing}
                    onOpenChange={setEditing}
                    maxLength={maxLength}
                />
            ) : null}
        </article>
    );
}

function EditDialog({
    document,
    open,
    onOpenChange,
    maxLength,
}: {
    document: PeopleDocumentData;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    maxLength: number;
}) {
    const id = useId();
    const [title, setTitle] = useState(document.title);
    const [body, setBody] = useState(document.body);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                onOpenChange(next);
                if (next) {
                    setTitle(document.title);
                    setBody(document.body);
                    setErrors({});
                }
            }}
        >
            <DialogContent className="max-w-3xl">
                <form
                    className="grid gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.put(
                            update.url(document.key),
                            { title, body },
                            {
                                preserveScroll: true,
                                onStart: () => setProcessing(true),
                                onFinish: () => setProcessing(false),
                                onError: (bag) => setErrors(bag),
                                onSuccess: () => onOpenChange(false),
                            },
                        );
                    }}
                >
                    <DialogTitle>
                        {t('people.documents.edit_title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('people.documents.edit_description')}
                    </DialogDescription>
                    <div className="grid gap-2">
                        <Label htmlFor={`${id}-title`}>
                            {t('people.documents.title_label')}
                        </Label>
                        <Input
                            id={`${id}-title`}
                            value={title}
                            onChange={(event) => setTitle(event.target.value)}
                            maxLength={200}
                            required
                        />
                        <InputError message={errors.title} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor={`${id}-body`}>
                            {t('people.documents.body_label')}
                        </Label>
                        <Textarea
                            id={`${id}-body`}
                            value={body}
                            onChange={(event) => setBody(event.target.value)}
                            maxLength={maxLength}
                            rows={16}
                            className="font-mono text-xs"
                            required
                        />
                        <InputError message={errors.body} />
                    </div>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                {t('common.cancel')}
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={processing}>
                            {processing ? <Spinner /> : null}
                            {t('people.documents.publish')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
