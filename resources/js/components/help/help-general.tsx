import { router } from '@inertiajs/react';
import {
    BookOpen,
    CircleDot,
    Clock,
    ExternalLink,
    Eye,
    EyeOff,
    FileText,
    Heart,
    LifeBuoy,
    Pencil,
    Plus,
    Search,
    Settings2,
    Sparkles,
    Trash2,
} from 'lucide-react';
import { useId, useMemo, useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { NativeSelect } from '@/components/admin/native-select';
import {
    HelpSettingsDialog,
    ManualUpdateDialog,
    ReleaseDialog,
} from '@/components/help/help-dialogs';
import { RichTextContent } from '@/components/rich-text/rich-text-content';
import { StatusBadge } from '@/components/styleguide/status-badges';
import { UserAvatar } from '@/components/tasks/task-fields';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/format';
import { filterUpdates, formatMegabytes } from '@/lib/help-center';
import type {
    HelpUpdateKindFilter,
    HelpUpdateStatusFilter,
} from '@/lib/help-center';
import { t } from '@/lib/i18n';
import { toggle as toggleLike } from '@/routes/help/likes';
import {
    destroy as hideRelease,
    update as updateRelease,
} from '@/routes/help/releases';
import { destroy as destroyUpdate } from '@/routes/help/updates';
import type {
    HelpReleaseOption,
    HelpSettings,
    HelpUpdateEntry,
    HelpUpdateStatus,
} from '@/types/weeklies';

const STATUS_META: Record<
    HelpUpdateStatus,
    { tone: 'success' | 'warning' | 'neutral'; icon: typeof Sparkles }
> = {
    new: { tone: 'success', icon: Sparkles },
    in_progress: { tone: 'warning', icon: Clock },
    previous: { tone: 'neutral', icon: CircleDot },
};

export function UpdateStatusBadge({ status }: { status: HelpUpdateStatus }) {
    const meta = STATUS_META[status];

    return (
        <StatusBadge tone={meta.tone} icon={meta.icon}>
            {t(`help.updates.status.${status}`)}
        </StatusBadge>
    );
}

/** Quién ha dado «me gusta» (F-153): los tres primeros avatares y la lista completa para leerla. */
function Likers({ entry }: { entry: HelpUpdateEntry }) {
    if (entry.likes.length === 0) {
        return null;
    }

    const names = entry.likes.map((like) => like.name).join(', ');

    return (
        <span
            className="inline-flex items-center gap-2"
            title={names}
            data-test="help-likers"
        >
            <span className="flex -space-x-1.5" aria-hidden="true">
                {entry.likes.slice(0, 3).map((like) => (
                    <UserAvatar
                        key={like.id}
                        user={like}
                        className="ring-2 ring-background"
                    />
                ))}
            </span>
            <span className="sr-only">{t('help.likes.who', { names })}</span>
            {entry.likes.length > 3 ? (
                <span aria-hidden="true">+{entry.likes.length - 3}</span>
            ) : null}
        </span>
    );
}

function LikeButton({ entry }: { entry: HelpUpdateEntry }) {
    const [processing, setProcessing] = useState(false);

    return (
        <Button
            type="button"
            variant={entry.liked_by_me ? 'default' : 'secondary'}
            size="sm"
            aria-pressed={entry.liked_by_me}
            disabled={processing}
            onClick={() =>
                router.post(
                    toggleLike.url(),
                    { kind: entry.kind, id: entry.id },
                    {
                        preserveScroll: true,
                        only: ['updates'],
                        onStart: () => setProcessing(true),
                        onFinish: () => setProcessing(false),
                    },
                )
            }
            aria-label={t(
                entry.liked_by_me ? 'help.likes.unlike' : 'help.likes.like',
                { title: entry.title, count: entry.likes.length },
            )}
            data-test="help-like"
        >
            <Heart
                aria-hidden="true"
                className={entry.liked_by_me ? 'fill-current' : undefined}
            />
            <span className="tabular">{entry.likes.length}</span>
        </Button>
    );
}

function UpdateDetail({
    entry,
    manage,
    onClose,
}: {
    entry: HelpUpdateEntry;
    manage: boolean;
    onClose: () => void;
}) {
    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);

    return (
        <Dialog open onOpenChange={(open) => (open ? null : onClose())}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{entry.title}</DialogTitle>
                    <DialogDescription>
                        {formatDate(entry.published_on)} · {entry.subtitle}
                    </DialogDescription>
                </DialogHeader>
                <div className="flex flex-wrap items-center gap-2">
                    <UpdateStatusBadge status={entry.status} />
                    {entry.kind === 'manual' ? (
                        <StatusBadge tone="info" icon={FileText}>
                            {t('help.updates.kind_manual')}
                        </StatusBadge>
                    ) : null}
                    {entry.version ? (
                        <StatusBadge tone="neutral" icon={BookOpen}>
                            {entry.version}
                        </StatusBadge>
                    ) : null}
                    <span className="flex-1" />
                    <LikeButton entry={entry} />
                    {manage && entry.release ? (
                        <>
                            <ReleaseDialog
                                release={entry.release}
                                trigger={
                                    <Button
                                        variant="secondary"
                                        size="sm"
                                        aria-label={t('help.releases.edit')}
                                    >
                                        <Pencil aria-hidden="true" />
                                        {t('common.edit')}
                                    </Button>
                                }
                            />
                            <ConfirmDialog
                                open={confirming}
                                onOpenChange={setConfirming}
                                trigger={
                                    <Button variant="secondary" size="sm">
                                        <EyeOff aria-hidden="true" />
                                        {t('help.releases.hide')}
                                    </Button>
                                }
                                title={t('help.releases.hide_title')}
                                description={t(
                                    'help.releases.hide_description',
                                )}
                                confirmLabel={t('help.releases.hide')}
                                processing={processing}
                                onConfirm={() => {
                                    const release = entry.release;

                                    if (!release) {
                                        return;
                                    }

                                    router.delete(hideRelease.url(release.id), {
                                        preserveScroll: true,
                                        onStart: () => setProcessing(true),
                                        onFinish: () => setProcessing(false),
                                        onSuccess: () => {
                                            setConfirming(false);
                                            onClose();
                                        },
                                    });
                                }}
                            />
                        </>
                    ) : null}
                    {manage && entry.manual ? (
                        <>
                            <ManualUpdateDialog
                                update={entry.manual}
                                trigger={
                                    <Button variant="secondary" size="sm">
                                        <Pencil aria-hidden="true" />
                                        {t('common.edit')}
                                    </Button>
                                }
                            />
                            <ConfirmDialog
                                open={confirming}
                                onOpenChange={setConfirming}
                                trigger={
                                    <Button variant="secondary" size="sm">
                                        <Trash2 aria-hidden="true" />
                                        {t('common.delete')}
                                    </Button>
                                }
                                title={t('help.updates.delete_title')}
                                description={t(
                                    'help.updates.delete_description',
                                )}
                                confirmLabel={t('common.delete')}
                                processing={processing}
                                onConfirm={() => {
                                    const manual = entry.manual;

                                    if (!manual) {
                                        return;
                                    }

                                    router.delete(
                                        destroyUpdate.url(manual.id),
                                        {
                                            preserveScroll: true,
                                            onStart: () => setProcessing(true),
                                            onFinish: () =>
                                                setProcessing(false),
                                            onSuccess: () => {
                                                setConfirming(false);
                                                onClose();
                                            },
                                        },
                                    );
                                }}
                            />
                        </>
                    ) : null}
                </div>
                {entry.release ? (
                    <div className="grid gap-3">
                        {entry.release.summary.trim() !== '' ? (
                            <p className="text-sm">{entry.release.summary}</p>
                        ) : null}
                        {entry.release.changes.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('help.releases.no_changes_yet')}
                            </p>
                        ) : (
                            <section className="grid gap-2">
                                <h3 className="text-sm font-medium">
                                    {t('help.releases.week_changes')}
                                </h3>
                                <ol className="list-decimal space-y-1 pl-5 text-sm">
                                    {entry.release.changes.map((change) => (
                                        <li
                                            key={change.id}
                                            className="whitespace-pre-line"
                                        >
                                            {change.description}
                                        </li>
                                    ))}
                                </ol>
                            </section>
                        )}
                    </div>
                ) : null}
                {entry.manual ? (
                    entry.manual.body.trim() === '' ? (
                        <p className="text-sm text-muted-foreground">
                            {t('help.updates.no_body')}
                        </p>
                    ) : (
                        <RichTextContent html={entry.manual.body} />
                    )
                ) : null}
                {entry.likes.length > 0 ? (
                    <section className="grid gap-2 border-t pt-3">
                        <h3 className="text-sm font-medium">
                            {t('help.likes.title', {
                                count: entry.likes.length,
                            })}
                        </h3>
                        <ul className="flex flex-wrap gap-2 text-sm">
                            {entry.likes.map((like) => (
                                <li
                                    key={like.id}
                                    className="inline-flex items-center gap-1.5"
                                >
                                    <UserAvatar user={like} />
                                    {like.name}
                                </li>
                            ))}
                        </ul>
                    </section>
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

/** Versiones ocultas (solo quien gestiona): para volver a mostrarlas en el listado. */
function HiddenReleases({ releases }: { releases: HelpReleaseOption[] }) {
    const hidden = releases.filter((release) => release.is_hidden);

    if (hidden.length === 0) {
        return null;
    }

    return (
        <details className="border px-4 py-3 text-sm">
            <summary className="cursor-pointer">
                {hidden.length === 1
                    ? t('help.releases.hidden_count_one')
                    : t('help.releases.hidden_count', { count: hidden.length })}
            </summary>
            <ul className="mt-3 grid gap-2">
                {hidden.map((release) => (
                    <li
                        key={release.id}
                        className="flex items-center justify-between gap-2"
                    >
                        <span>{release.version}</span>
                        <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            onClick={() =>
                                router.put(
                                    updateRelease.url(release.id),
                                    {
                                        major_version: release.major_version,
                                        month_number: release.month_number,
                                        week_of_month: release.week_of_month,
                                        is_hidden: false,
                                    },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <Eye aria-hidden="true" />
                            {t('help.releases.show')}
                        </Button>
                    </li>
                ))}
            </ul>
        </details>
    );
}

/**
 * Pestaña General (F-150 a F-153 y F-157): el manual y el soporte, y las novedades con su buscador,
 * los filtros de tipo y estado, los «me gusta» y el detalle. Quien gestiona añade novedades y
 * versiones, las edita y oculta, y cambia el manual y el enlace de soporte.
 */
export function HelpGeneral({
    updates,
    settings,
    releases,
    manage,
    attachmentMaxMb,
}: {
    updates: HelpUpdateEntry[];
    settings: HelpSettings;
    releases: HelpReleaseOption[] | null;
    manage: boolean;
    attachmentMaxMb: number;
}) {
    const id = useId();
    const [q, setQ] = useState('');
    const [kind, setKind] = useState<HelpUpdateKindFilter>('all');
    const [status, setStatus] = useState<HelpUpdateStatusFilter>('all');
    const [openKey, setOpenKey] = useState<string | null>(null);
    const visible = useMemo(
        () => filterUpdates(updates, { q, kind, status }),
        [updates, q, kind, status],
    );
    const opened = updates.find((entry) => entry.key === openKey) ?? null;
    const latest = releases?.[0];

    return (
        <div className="grid gap-6">
            <section
                aria-labelledby={`${id}-resources`}
                className="grid gap-3 border p-4"
            >
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2
                        id={`${id}-resources`}
                        className="text-base font-medium"
                    >
                        {t('help.resources.title')}
                    </h2>
                    {manage ? (
                        <HelpSettingsDialog
                            settings={settings}
                            maxMegabytes={attachmentMaxMb}
                            trigger={
                                <Button variant="secondary" size="sm">
                                    <Settings2 aria-hidden="true" />
                                    {t('help.resources.edit')}
                                </Button>
                            }
                        />
                    ) : null}
                </div>
                <div className="flex flex-wrap gap-3">
                    {settings.manual ? (
                        <Button variant="secondary" asChild>
                            <a
                                href={settings.manual.url}
                                target="_blank"
                                rel="noreferrer"
                                data-test="help-manual"
                            >
                                <BookOpen aria-hidden="true" />
                                {t('help.resources.manual', {
                                    size: formatMegabytes(settings.manual.size),
                                })}
                            </a>
                        </Button>
                    ) : null}
                    {settings.support_url ? (
                        <Button variant="secondary" asChild>
                            <a
                                href={settings.support_url}
                                target="_blank"
                                rel="noopener noreferrer"
                                data-test="help-support"
                            >
                                <LifeBuoy aria-hidden="true" />
                                {t('help.resources.support')}
                                <ExternalLink
                                    aria-hidden="true"
                                    className="size-3.5"
                                />
                            </a>
                        </Button>
                    ) : null}
                    {!settings.manual && !settings.support_url ? (
                        <p className="text-sm text-muted-foreground">
                            {t('help.resources.empty')}
                        </p>
                    ) : null}
                </div>
            </section>

            <section aria-labelledby={`${id}-updates`} className="grid gap-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 id={`${id}-updates`} className="text-base font-medium">
                        {t('help.updates.title')}
                    </h2>
                    {manage ? (
                        <div className="flex flex-wrap gap-2">
                            <ReleaseDialog
                                release={null}
                                defaults={
                                    latest
                                        ? {
                                              major_version:
                                                  latest.major_version,
                                              month_number: latest.month_number,
                                          }
                                        : undefined
                                }
                                trigger={
                                    <Button variant="secondary">
                                        <Plus aria-hidden="true" />
                                        {t('help.releases.add')}
                                    </Button>
                                }
                            />
                            <ManualUpdateDialog
                                update={null}
                                trigger={
                                    <Button>
                                        <Plus aria-hidden="true" />
                                        {t('help.updates.add')}
                                    </Button>
                                }
                            />
                        </div>
                    ) : null}
                </div>
                <div className="grid gap-3 sm:grid-cols-[1fr_auto_auto]">
                    <div className="relative">
                        <Search
                            aria-hidden="true"
                            className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            type="search"
                            value={q}
                            onChange={(event) => setQ(event.target.value)}
                            placeholder={t('help.updates.search_placeholder')}
                            aria-label={t('help.updates.search')}
                            className="pl-8"
                        />
                    </div>
                    <NativeSelect
                        value={kind}
                        onChange={(event) =>
                            setKind(event.target.value as HelpUpdateKindFilter)
                        }
                        aria-label={t('help.updates.kind_filter')}
                    >
                        <option value="all">
                            {t('help.updates.kind.all')}
                        </option>
                        <option value="release">
                            {t('help.updates.kind.release')}
                        </option>
                        <option value="manual">
                            {t('help.updates.kind.manual')}
                        </option>
                    </NativeSelect>
                    <NativeSelect
                        value={status}
                        onChange={(event) =>
                            setStatus(
                                event.target.value as HelpUpdateStatusFilter,
                            )
                        }
                        aria-label={t('help.updates.status_filter')}
                    >
                        <option value="all">
                            {t('help.updates.status.all')}
                        </option>
                        <option value="new">
                            {t('help.updates.status.new')}
                        </option>
                        <option value="in_progress">
                            {t('help.updates.status.in_progress')}
                        </option>
                        <option value="previous">
                            {t('help.updates.status.previous_plural')}
                        </option>
                    </NativeSelect>
                </div>
                <p className="sr-only" aria-live="polite">
                    {visible.length === 1
                        ? t('help.updates.count_one')
                        : t('help.updates.count', { count: visible.length })}
                </p>
                {visible.length === 0 ? (
                    <p className="border px-4 py-8 text-center text-sm text-muted-foreground">
                        {t('help.updates.empty')}
                    </p>
                ) : (
                    <ul className="grid border" data-test="help-updates">
                        {visible.map((entry, index) => (
                            <li
                                key={entry.key}
                                className={index > 0 ? 'border-t p-4' : 'p-4'}
                            >
                                <article className="grid gap-2 sm:grid-cols-[8rem_1fr_auto] sm:items-start">
                                    <p className="tabular text-sm text-muted-foreground">
                                        {formatDate(entry.published_on)}
                                    </p>
                                    <div className="grid min-w-0 gap-1.5">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <UpdateStatusBadge
                                                status={entry.status}
                                            />
                                            {entry.kind === 'manual' ? (
                                                <StatusBadge
                                                    tone="info"
                                                    icon={FileText}
                                                >
                                                    {t(
                                                        'help.updates.kind_manual',
                                                    )}
                                                </StatusBadge>
                                            ) : null}
                                            {entry.version ? (
                                                <StatusBadge
                                                    tone="neutral"
                                                    icon={BookOpen}
                                                >
                                                    {entry.version}
                                                </StatusBadge>
                                            ) : null}
                                        </div>
                                        <h3 className="font-medium">
                                            {entry.title}
                                        </h3>
                                        {entry.subtitle ? (
                                            <p className="text-sm text-muted-foreground">
                                                {entry.subtitle}
                                            </p>
                                        ) : null}
                                        <div className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                                            {entry.release ? (
                                                <span>
                                                    {entry.release.changes
                                                        .length === 1
                                                        ? t(
                                                              'help.releases.changes_count_one',
                                                          )
                                                        : t(
                                                              'help.releases.changes_count',
                                                              {
                                                                  count: entry
                                                                      .release
                                                                      .changes
                                                                      .length,
                                                              },
                                                          )}
                                                </span>
                                            ) : null}
                                            <Likers entry={entry} />
                                        </div>
                                    </div>
                                    <div className="flex gap-2 sm:justify-end">
                                        <LikeButton entry={entry} />
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            size="sm"
                                            onClick={() =>
                                                setOpenKey(entry.key)
                                            }
                                            aria-label={t('help.updates.open', {
                                                title: entry.title,
                                            })}
                                        >
                                            {t('help.updates.view')}
                                        </Button>
                                    </div>
                                </article>
                            </li>
                        ))}
                    </ul>
                )}
                {manage && releases ? (
                    <HiddenReleases releases={releases} />
                ) : null}
            </section>
            {opened ? (
                <UpdateDetail
                    entry={opened}
                    manage={manage}
                    onClose={() => setOpenKey(null)}
                />
            ) : null}
        </div>
    );
}
