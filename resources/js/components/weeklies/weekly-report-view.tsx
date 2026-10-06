import { Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    Briefcase,
    CalendarCog,
    Check,
    ChevronRight,
    Copy,
    Download,
    FileCode,
    FileText,
    Headphones,
    List,
    Loader2,
    Lock,
    Maximize2,
    Minimize2,
    MoreHorizontal,
    Pencil,
    RefreshCw,
    Trash2,
    Wand2,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { ExportMenu } from '@/components/reports/export-menu';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { ClientReportCard } from '@/components/weeklies/client-report-card';
import {
    LegacyReportText,
    reportTextSections,
} from '@/components/weeklies/legacy-report-text';
import { ReportEditDialog } from '@/components/weeklies/report-edit-dialog';
import { TeamStatusStrip } from '@/components/weeklies/team-status-strip';
import {
    InlineAudioPlayer,
    ReportAudioPlayer,
} from '@/components/weeklies/weekly-audio';
import {
    closeHint,
    CloseWeekDialog,
} from '@/components/weeklies/weekly-close-dialog';
import { DeadlineDialog } from '@/components/weeklies/weekly-dialogs';
import {
    compactWeekLabel,
    isPendingStatus,
} from '@/components/weeklies/weekly-ui';
import {
    isBusy,
    useWeeklyProgress,
} from '@/components/weeklies/use-weekly-progress';
import type { WeeklyJob } from '@/components/weeklies/use-weekly-progress';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import {
    destroy as destroyCycle,
    index as weekliesIndex,
} from '@/routes/weeklies';
import {
    download as audioDownload,
    store as storeAudio,
} from '@/routes/weeklies/audio';
import {
    pdf as reportPdf,
    store as storeReport,
} from '@/routes/weeklies/report';
import type {
    WeeklyClientUpdate,
    WeeklyOriginalReport,
    WeeklyShowPageProps,
} from '@/types/weeklies';

/** Ancla de un cliente en el índice. */
export function clientAnchor(
    update: WeeklyClientUpdate,
    index: number,
): string {
    return update.client_id === null
        ? 'cliente-general'
        : `cliente-${update.client_id}-${index}`;
}

/** Clave de la sección de audio de un cliente (WeeklyAudioScripts::clientKey). */
export function audioKey(clientId: number | null): string {
    return clientId === null ? 'client-general' : `client-${clientId}`;
}

/**
 * Texto para copiar (F-082): el texto final del informe (Markdown del servidor) o, si no lo hay,
 * el que montaba el original con el informe estructurado.
 */
export function weeklyClipboardText(
    cycle: WeeklyShowPageProps['cycle'],
): string {
    if (cycle.report_text?.trim()) {
        return cycle.report_text.trim();
    }

    const report = cycle.report;

    if (!report) {
        return '';
    }

    const lines = [
        `# Weekly ${cycle.number} - ${cycle.label}`,
        t('weeklies.copy.deadline', { date: formatDate(cycle.deadline_date) }),
        '',
        `## ${t('weeklies.report.global_summary')}`,
        report.global_summary,
    ];

    if (report.team_risks.length > 0) {
        lines.push('', `## ${t('weeklies.report.team_risks')}`);
        report.team_risks.forEach((risk) => lines.push(`- ${risk}`));
    }

    report.client_updates.forEach((update) => {
        lines.push(
            '',
            `## ${update.client_name}`,
            `${t('weeklies.edit.status')}: ${t(`weeklies.client_status.${update.status}`)}`,
            '',
            update.executive_summary,
        );
    });

    return lines.join('\n').trim();
}

/** «Clientes: 3 de 12», «Locutando: 2 de 9»… */
function progressText(job: WeeklyJob): string {
    const detail = job.detail;

    if (job.state === 'queued' || !detail || detail.step === null) {
        return t('weeklies.progress.queued');
    }

    return t(`weeklies.progress.${detail.step}`, {
        done: detail.done,
        total: detail.total,
    });
}

/**
 * El informe de una semana (F-072 a F-091, ReportView de WeeklySync): índice de clientes (en el
 * móvil, en un panel), cabecera con las acciones, el estado del equipo, el audio y el informe con
 * una tarjeta por cliente. Quien gestiona genera el texto y el audio (con su progreso), lo edita,
 * cambia el plazo, cierra y borra la semana. Cualquiera de la plantilla filtra «Solo mis
 * proyectos», lo ve a pantalla completa, copia el texto, lo exporta (PDF, imprimir, Excel, CSV y
 * HTML), descarga el audio y lee los reportes originales de cada cliente.
 */
export function WeeklyReportView(props: WeeklyShowPageProps) {
    const { cycle, can, team, reports, close } = props;
    const report = cycle.report;
    const closed = cycle.status === 'closed';
    const [onlyMine, setOnlyMine] = useState(false);
    const [fullscreen, setFullscreen] = useState(false);
    const [indexOpen, setIndexOpen] = useState(false);
    const [actionsOpen, setActionsOpen] = useState(false);
    const [copied, setCopied] = useState(false);
    const [originals, setOriginals] = useState<{
        name: string;
        reports: WeeklyOriginalReport[];
    } | null>(null);
    const [deleting, setDeleting] = useState(false);
    const jobs = useWeeklyProgress({
        cycleId: cycle.id,
        report: {
            state: cycle.report_state,
            detail: props.progress.report,
            error: cycle.report_error,
        },
        audio: {
            state: cycle.audio_state,
            detail: props.progress.audio,
            error: cycle.audio_error,
        },
    });
    const generating = isBusy(jobs.report.state) || isBusy(jobs.audio.state);
    const mine = useMemo(
        () => new Set(props.my_client_ids),
        [props.my_client_ids],
    );
    const updates = useMemo(() => {
        const all = (report?.client_updates ?? []).map((update, index) => ({
            update,
            anchor: clientAnchor(update, index),
        }));

        return onlyMine
            ? all.filter(
                  ({ update }) =>
                      update.client_id !== null && mine.has(update.client_id),
              )
            : all;
    }, [report, onlyMine, mine]);
    // Semana importada de WeeklySync con solo el texto final (sin informe estructurado, 10.9b).
    const legacy = useMemo(
        () =>
            !report && cycle.report_text?.trim()
                ? reportTextSections(cycle.report_text)
                : null,
        [report, cycle.report_text],
    );
    const sections = cycle.audio_sections ?? [];
    const sectionByKey = new Map(
        sections.map((section) => [section.key, section]),
    );
    const labels = Object.fromEntries(
        (report?.client_updates ?? []).map((update) => [
            audioKey(update.client_id),
            update.client_name,
        ]),
    );
    const fullAudioUrl = cycle.has_full_audio
        ? audioDownload.url(cycle.id)
        : null;
    const exportRequest = onlyMine
        ? {
              ...props.report_request,
              query: { ...props.report_request.query, mios: 1 },
          }
        : props.report_request;
    const hint = closeHint(close.blockers, close.pending);

    useEffect(() => {
        if (!fullscreen) {
            return;
        }

        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setFullscreen(false);
            }
        };

        document.addEventListener('keydown', onKey);

        return () => document.removeEventListener('keydown', onKey);
    }, [fullscreen]);

    const copy = async () => {
        const text = weeklyClipboardText(cycle);

        if (!text) {
            return;
        }

        try {
            await navigator.clipboard.writeText(text);
        } catch {
            const area = document.createElement('textarea');
            area.value = text;
            area.style.position = 'fixed';
            area.style.left = '-9999px';
            document.body.appendChild(area);
            area.select();
            document.execCommand('copy');
            document.body.removeChild(area);
        }

        setCopied(true);
        window.setTimeout(() => setCopied(false), 1600);
    };

    const scrollTo = (anchor: string) => {
        document.getElementById(anchor)?.scrollIntoView({ behavior: 'smooth' });
        setIndexOpen(false);
    };

    const [requestingText, setRequestingText] = useState(false);
    const generateText = () =>
        router.post(
            storeReport.url(cycle.id),
            {},
            {
                preserveScroll: true,
                onStart: () => setRequestingText(true),
                onFinish: () => setRequestingText(false),
            },
        );
    // Regenerar descarta la edición a mano (D-190): se avisa antes (10.9b).
    const editedByHand = !!report && !!cycle.report_edited_at && !closed;
    const generateAudio = () =>
        router.post(storeAudio.url(cycle.id), {}, { preserveScroll: true });

    const textLabel = closed
        ? t('weeklies.actions.text_closed')
        : props.stale && report
          ? t('weeklies.actions.update_text')
          : report
            ? t('weeklies.actions.regenerate_text')
            : t('weeklies.actions.generate_text');
    const audioLabel = cycle.has_full_audio
        ? t('weeklies.actions.regenerate_audio')
        : t('weeklies.actions.generate_audio');

    const toc = (
        <nav aria-label={t('weeklies.report.index')} data-test="weekly-index">
            {legacy ? (
                <ul className="grid gap-1">
                    {legacy
                        .filter((section) => section.title !== null)
                        .map((section) => (
                            <li key={section.anchor}>
                                <a
                                    href={`#${section.anchor}`}
                                    className={cn(
                                        'block truncate px-3 py-2 text-sm text-muted-foreground hover:bg-accent hover:text-foreground',
                                        FOCUS_RING,
                                    )}
                                    onClick={(event) => {
                                        event.preventDefault();
                                        scrollTo(section.anchor);
                                    }}
                                >
                                    {section.title}
                                </a>
                            </li>
                        ))}
                </ul>
            ) : updates.length > 0 ? (
                <ul className="grid gap-1">
                    {updates.map(({ update, anchor }) => (
                        <li key={anchor}>
                            <a
                                href={`#${anchor}`}
                                className={cn(
                                    'block truncate px-3 py-2 text-sm text-muted-foreground hover:bg-accent hover:text-foreground',
                                    FOCUS_RING,
                                )}
                                onClick={(event) => {
                                    event.preventDefault();
                                    scrollTo(anchor);
                                }}
                            >
                                {update.client_name}
                            </a>
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="p-3 text-sm text-muted-foreground">
                    {t('weeklies.report.index_empty')}
                </p>
            )}
        </nav>
    );

    const generative = can.generate ? (
        <div className="grid gap-2" data-test="weekly-generative">
            {generating ? (
                <p
                    className="flex items-start gap-2 bg-info-soft p-2 text-xs"
                    role="status"
                    data-test="weekly-generating"
                >
                    <Loader2
                        aria-hidden="true"
                        className="mt-0.5 size-3.5 shrink-0 animate-spin"
                    />
                    <span>
                        {t('weeklies.progress.wait')}{' '}
                        {isBusy(jobs.report.state)
                            ? `${t('weeklies.progress.report')}: ${progressText(jobs.report)}`
                            : `${t('weeklies.progress.audio')}: ${progressText(jobs.audio)}`}
                    </span>
                </p>
            ) : null}
            {jobs.report.state === 'failed' && jobs.report.error ? (
                <p className="bg-danger-soft p-2 text-xs" role="alert">
                    {jobs.report.error}
                </p>
            ) : null}
            {jobs.audio.state === 'failed' && jobs.audio.error ? (
                <p className="bg-danger-soft p-2 text-xs" role="alert">
                    {jobs.audio.error}
                </p>
            ) : null}
            {editedByHand ? (
                <ConfirmDialog
                    trigger={
                        <Button
                            type="button"
                            variant={
                                props.stale && report && !closed
                                    ? 'default'
                                    : 'outline'
                            }
                            size="sm"
                            className="justify-start"
                            disabled={generating || closed}
                            data-test="weekly-generate-text"
                        >
                            {isBusy(jobs.report.state) ? (
                                <Loader2
                                    aria-hidden="true"
                                    className="animate-spin"
                                />
                            ) : props.stale && report ? (
                                <RefreshCw aria-hidden="true" />
                            ) : (
                                <Wand2 aria-hidden="true" />
                            )}
                            {textLabel}
                        </Button>
                    }
                    title={t('weeklies.report.regenerate_edited_title')}
                    description={t(
                        'weeklies.report.regenerate_edited_description',
                    )}
                    confirmLabel={textLabel}
                    processing={requestingText}
                    onConfirm={generateText}
                />
            ) : (
                <Button
                    type="button"
                    variant={
                        props.stale && report && !closed ? 'default' : 'outline'
                    }
                    size="sm"
                    className="justify-start"
                    disabled={generating || closed}
                    onClick={generateText}
                    data-test="weekly-generate-text"
                >
                    {isBusy(jobs.report.state) ? (
                        <Loader2 aria-hidden="true" className="animate-spin" />
                    ) : props.stale && report ? (
                        <RefreshCw aria-hidden="true" />
                    ) : (
                        <Wand2 aria-hidden="true" />
                    )}
                    {textLabel}
                </Button>
            )}
            <Button
                type="button"
                variant="outline"
                size="sm"
                className="justify-start"
                disabled={generating || !report}
                title={
                    report ? undefined : t('weeklies.errors.audio_needs_report')
                }
                onClick={generateAudio}
                data-test="weekly-generate-audio"
            >
                {isBusy(jobs.audio.state) ? (
                    <Loader2 aria-hidden="true" className="animate-spin" />
                ) : (
                    <Headphones aria-hidden="true" />
                )}
                {audioLabel}
            </Button>
        </div>
    ) : null;

    const downloads = (
        <div className="grid gap-2">
            <Button
                asChild
                variant="outline"
                size="sm"
                className="justify-start"
            >
                <a
                    href={reportPdf.url(cycle.id, {
                        query: {
                            formato: 'html',
                            ...(onlyMine ? { mios: 1 } : {}),
                        },
                    })}
                    data-test="weekly-download-html"
                >
                    <FileCode aria-hidden="true" />
                    {t('weeklies.actions.download_html')}
                </a>
            </Button>
            {fullAudioUrl ? (
                <Button
                    asChild
                    variant="outline"
                    size="sm"
                    className="justify-start"
                >
                    <a
                        href={audioDownload.url(cycle.id, {
                            query: { descargar: 1 },
                        })}
                        data-test="weekly-download-audio"
                    >
                        <Download aria-hidden="true" />
                        {t('weeklies.actions.download_audio')}
                    </a>
                </Button>
            ) : null}
        </div>
    );

    const structural = (
        <div className="flex flex-wrap items-center gap-2">
            {can.edit && report ? (
                <ReportEditDialog
                    cycle={cycle}
                    report={report}
                    trigger={
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            data-test="weekly-edit"
                        >
                            <Pencil aria-hidden="true" />
                            {t('weeklies.actions.edit')}
                        </Button>
                    }
                />
            ) : null}
            <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={!report && !cycle.report_text}
                onClick={() => void copy()}
                data-test="weekly-copy"
            >
                {copied ? (
                    <Check aria-hidden="true" />
                ) : (
                    <Copy aria-hidden="true" />
                )}
                {copied
                    ? t('weeklies.actions.copied')
                    : t('weeklies.actions.copy')}
            </Button>
            {can.extendDeadline ? (
                <DeadlineDialog
                    cycle={cycle}
                    trigger={
                        <Button type="button" variant="outline" size="sm">
                            <CalendarCog aria-hidden="true" />
                            {t('weeklies.deadline.open')}
                        </Button>
                    }
                />
            ) : null}
            {can.delete ? (
                <ConfirmDialog
                    trigger={
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            data-test="weekly-delete"
                        >
                            <Trash2 aria-hidden="true" />
                            {t('weeklies.history.delete')}
                        </Button>
                    }
                    title={t('weeklies.history.delete_title', {
                        label: cycle.label,
                    })}
                    description={t('weeklies.history.delete_description')}
                    confirmLabel={t('weeklies.history.delete')}
                    processing={deleting}
                    onConfirm={() =>
                        router.delete(destroyCycle.url(cycle.id), {
                            onStart: () => setDeleting(true),
                            onFinish: () => setDeleting(false),
                        })
                    }
                />
            ) : null}
            {can.close ? (
                <CloseWeekDialog
                    cycle={cycle}
                    blockers={close.blockers}
                    pending={close.pending}
                    pendingNames={team.members
                        .filter((member) => isPendingStatus(member.status))
                        .map((member) => member.user.name)}
                />
            ) : null}
        </div>
    );

    return (
        <div
            className={cn(
                'flex min-w-0 flex-col',
                fullscreen
                    ? 'fixed inset-0 z-50 overflow-y-auto bg-background p-4 md:p-8'
                    : 'mx-auto w-full max-w-7xl gap-4 p-4 md:p-6',
            )}
            data-test="weekly-report-view"
            data-fullscreen={fullscreen ? 'true' : 'false'}
        >
            {!fullscreen ? (
                <Link
                    href={weekliesIndex.url({
                        query: { pestana: 'historico' },
                    })}
                    className={cn(
                        'inline-flex items-center gap-1 self-start text-sm text-muted-foreground hover:text-foreground',
                        FOCUS_RING,
                    )}
                >
                    <ArrowLeft aria-hidden="true" className="size-4" />
                    {t('weeklies.report.back')}
                </Link>
            ) : null}

            <div className="flex min-w-0 flex-col border bg-card md:flex-row">
                <aside className="hidden w-64 shrink-0 flex-col border-r md:flex">
                    <h2 className="flex items-center gap-2 border-b bg-muted/50 p-4 text-sm">
                        <List aria-hidden="true" className="size-4" />
                        {t('weeklies.report.index')}
                    </h2>
                    <div className="max-h-[60vh] overflow-y-auto p-2">
                        {toc}
                    </div>
                    {!fullscreen ? (
                        <div className="grid gap-3 border-t bg-muted/50 p-4">
                            <h2 className="text-xs tracking-wider text-muted-foreground uppercase">
                                {t('weeklies.actions.title')}
                            </h2>
                            {closed ? (
                                <p className="flex items-center gap-2 text-xs text-muted-foreground">
                                    <Lock
                                        aria-hidden="true"
                                        className="size-3.5"
                                    />
                                    {t('weeklies.report.finished')}
                                </p>
                            ) : null}
                            {!closed && props.stale && report ? (
                                <p
                                    className="flex items-center gap-2 bg-warning-soft p-2 text-xs"
                                    data-test="weekly-stale"
                                >
                                    <AlertTriangle
                                        aria-hidden="true"
                                        className="size-3.5 text-warning"
                                    />
                                    {t('weeklies.cycle.report_stale')}
                                </p>
                            ) : null}
                            {generative}
                            {downloads}
                        </div>
                    ) : null}
                </aside>

                <div className="flex min-w-0 flex-1 flex-col">
                    <header className="grid gap-4 border-b p-4 md:p-6">
                        <div className="flex items-center justify-between gap-2 md:hidden">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setIndexOpen(true)}
                                data-test="weekly-index-mobile"
                            >
                                <List aria-hidden="true" />
                                {t('weeklies.report.show_index')}
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                aria-label={t('weeklies.actions.more')}
                                onClick={() => setActionsOpen(true)}
                                data-test="weekly-actions-mobile"
                            >
                                <MoreHorizontal aria-hidden="true" />
                            </Button>
                        </div>

                        <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div className="min-w-0">
                                <h1 className="text-xl md:text-2xl">
                                    <span className="md:hidden">
                                        {compactWeekLabel(cycle)}
                                    </span>
                                    <span className="hidden md:inline">
                                        {cycle.label}
                                    </span>
                                </h1>
                                <p className="text-sm text-muted-foreground">
                                    {cycle.number} ·{' '}
                                    {t(`weeklies.cycle_status.${cycle.status}`)}{' '}
                                    ·{' '}
                                    {t('weeklies.cycle.deadline', {
                                        date: formatDate(cycle.deadline_date),
                                    })}
                                </p>
                                {can.close ? (
                                    <p
                                        className="mt-1 text-xs text-muted-foreground"
                                        data-test="weekly-close-hint"
                                    >
                                        {hint}
                                    </p>
                                ) : null}
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                {report && report.client_updates.length > 0 ? (
                                    <Button
                                        type="button"
                                        variant={
                                            onlyMine ? 'default' : 'outline'
                                        }
                                        size="sm"
                                        aria-pressed={onlyMine}
                                        onClick={() =>
                                            setOnlyMine((value) => !value)
                                        }
                                        data-test="weekly-only-mine"
                                    >
                                        <Briefcase aria-hidden="true" />
                                        {t('weeklies.report.only_mine')}
                                    </Button>
                                ) : null}
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    aria-pressed={fullscreen}
                                    aria-label={
                                        fullscreen
                                            ? t(
                                                  'weeklies.report.exit_fullscreen',
                                              )
                                            : t('weeklies.report.fullscreen')
                                    }
                                    title={
                                        fullscreen
                                            ? t(
                                                  'weeklies.report.exit_fullscreen',
                                              )
                                            : t('weeklies.report.fullscreen')
                                    }
                                    onClick={() =>
                                        setFullscreen((value) => !value)
                                    }
                                    data-test="weekly-fullscreen"
                                >
                                    {fullscreen ? (
                                        <Minimize2 aria-hidden="true" />
                                    ) : (
                                        <Maximize2 aria-hidden="true" />
                                    )}
                                </Button>
                                {!fullscreen ? (
                                    <>
                                        <ExportMenu
                                            request={exportRequest}
                                            title={`Weekly · ${cycle.number}`}
                                            size="sm"
                                        />
                                        <div className="hidden md:block">
                                            {structural}
                                        </div>
                                    </>
                                ) : null}
                            </div>
                        </div>

                        {!fullscreen ? (
                            <div className="grid gap-2">
                                <h2 className="text-xs tracking-wider text-muted-foreground uppercase">
                                    {t('weeklies.team.label')}
                                </h2>
                                <TeamStatusStrip team={team} />
                            </div>
                        ) : null}

                        {fullAudioUrl && !onlyMine && !fullscreen ? (
                            <ReportAudioPlayer
                                src={fullAudioUrl}
                                playerId={`weekly-main-${cycle.id}`}
                                sections={sections}
                                labels={labels}
                            />
                        ) : null}
                    </header>

                    <div className="grid gap-8 p-4 md:p-8">
                        {report ? (
                            <>
                                <section
                                    aria-labelledby="weekly-global-title"
                                    className="grid gap-4"
                                >
                                    <h2
                                        id="weekly-global-title"
                                        className="text-2xl"
                                    >
                                        {t('weeklies.report.global_summary')}
                                    </h2>
                                    <p className="whitespace-pre-line text-muted-foreground">
                                        {report.global_summary}
                                    </p>
                                    {report.team_risks.length > 0 ? (
                                        <div
                                            className="border-l-4 border-danger bg-danger-soft p-4"
                                            data-test="weekly-risks"
                                        >
                                            <h3 className="mb-2 flex items-center gap-2 text-sm">
                                                <AlertTriangle
                                                    aria-hidden="true"
                                                    className="size-4 text-danger"
                                                />
                                                {t(
                                                    'weeklies.report.team_risks',
                                                )}
                                            </h3>
                                            <ul className="list-inside list-disc text-sm">
                                                {report.team_risks.map(
                                                    (risk, index) => (
                                                        <li key={index}>
                                                            {risk}
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                        </div>
                                    ) : null}
                                </section>

                                {onlyMine && updates.length === 0 ? (
                                    <p
                                        className="border border-dashed p-6 text-sm text-muted-foreground"
                                        data-test="weekly-only-mine-empty"
                                    >
                                        {t('weeklies.report.only_mine_empty')}
                                    </p>
                                ) : null}

                                {updates.map(({ update, anchor }) => {
                                    const section = sectionByKey.get(
                                        audioKey(update.client_id),
                                    );
                                    const clientReports =
                                        reports[
                                            update.client_id === null
                                                ? 'general'
                                                : String(update.client_id)
                                        ] ?? [];

                                    return (
                                        <ClientReportCard
                                            key={anchor}
                                            update={update}
                                            anchor={anchor}
                                            audio={
                                                section?.url ? (
                                                    <InlineAudioPlayer
                                                        src={section.url}
                                                        name={
                                                            update.client_name
                                                        }
                                                        playerId={`weekly-client-${cycle.id}-${section.key}`}
                                                    />
                                                ) : undefined
                                            }
                                            reportsAction={
                                                clientReports.length > 0 ? (
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            setOriginals({
                                                                name: update.client_name,
                                                                reports:
                                                                    clientReports,
                                                            })
                                                        }
                                                        data-test="weekly-originals"
                                                    >
                                                        {t(
                                                            'weeklies.report.originals',
                                                            {
                                                                count: clientReports.length,
                                                            },
                                                        )}
                                                        <ChevronRight aria-hidden="true" />
                                                    </Button>
                                                ) : undefined
                                            }
                                        />
                                    );
                                })}
                            </>
                        ) : legacy ? (
                            <LegacyReportText sections={legacy} />
                        ) : (
                            <div className="grid justify-items-center gap-2 py-16 text-center text-muted-foreground">
                                <FileText
                                    aria-hidden="true"
                                    className="size-12 opacity-30"
                                />
                                <p>{t('weeklies.report.empty')}</p>
                                <p className="text-sm">
                                    {closed
                                        ? t('weeklies.report.empty_closed')
                                        : t('weeklies.report.empty_hint')}
                                </p>
                            </div>
                        )}
                    </div>
                </div>
            </div>

            <Sheet open={indexOpen} onOpenChange={setIndexOpen}>
                <SheetContent side="right" className="w-[86vw] max-w-xs">
                    <SheetHeader>
                        <SheetTitle>{t('weeklies.report.index')}</SheetTitle>
                        <SheetDescription className="sr-only">
                            {cycle.label}
                        </SheetDescription>
                    </SheetHeader>
                    <div className="overflow-y-auto px-2">{toc}</div>
                </SheetContent>
            </Sheet>

            <Sheet open={actionsOpen} onOpenChange={setActionsOpen}>
                <SheetContent
                    side="bottom"
                    className="max-h-[85dvh] overflow-y-auto"
                >
                    <SheetHeader>
                        <SheetTitle>{t('weeklies.actions.title')}</SheetTitle>
                        <SheetDescription className="sr-only">
                            {cycle.label}
                        </SheetDescription>
                    </SheetHeader>
                    <div className="grid gap-4 px-4 pb-4">
                        <section className="grid gap-2">
                            <h3 className="text-xs tracking-wider text-muted-foreground uppercase">
                                {t('weeklies.actions.structural')}
                            </h3>
                            {structural}
                        </section>
                        <section className="grid gap-2 border bg-info-soft/40 p-3">
                            <h3 className="text-xs tracking-wider text-muted-foreground uppercase">
                                {t('weeklies.actions.generative')}
                            </h3>
                            {generative}
                            {downloads}
                        </section>
                    </div>
                </SheetContent>
            </Sheet>

            <Dialog
                open={originals !== null}
                onOpenChange={(open) => (open ? null : setOriginals(null))}
            >
                <DialogContent className="max-h-[calc(100dvh-2rem)] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>
                            {t('weeklies.report.originals_title', {
                                name: originals?.name ?? '',
                            })}
                        </DialogTitle>
                        <DialogDescription>{cycle.label}</DialogDescription>
                    </DialogHeader>
                    <ul
                        className="grid gap-3"
                        data-test="weekly-originals-list"
                    >
                        {(originals?.reports ?? []).map((item, index) => (
                            <li
                                key={index}
                                className="grid gap-1 border bg-muted/40 p-3"
                            >
                                <p className="text-sm">
                                    {item.author.name}
                                    <span className="text-muted-foreground">
                                        {' · '}
                                        {formatDate(item.submitted_at)}
                                    </span>
                                </p>
                                <p className="text-sm whitespace-pre-line text-muted-foreground">
                                    {item.body}
                                </p>
                            </li>
                        ))}
                    </ul>
                </DialogContent>
            </Dialog>
        </div>
    );
}
