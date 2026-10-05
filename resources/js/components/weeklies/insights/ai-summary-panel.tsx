import { router } from '@inertiajs/react';
import { Bot, CircleAlert, RefreshCw, Sparkles } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useState } from 'react';
import { SafeMarkdown } from '@/components/privacy/safe-markdown';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { AiSummary } from '@/types/weekly-insights';

/** Cada cuánto se mira si el resumen ya está mientras se genera. */
export const AI_POLL_MS = 3000;

export function isGenerating(summary: AiSummary | null | undefined): boolean {
    return (
        summary !== null &&
        summary !== undefined &&
        (summary.state === 'queued' || summary.state === 'running') &&
        !summary.stuck
    );
}

/**
 * Mientras el resumen se genera, recarga solo la prop que lo trae (Inertia), cada 3 s, hasta que
 * termina. Sin Reverb: como la recarga de datos de D-184.
 */
export function useAiSummaryPolling(
    summary: AiSummary | null | undefined,
    reloadProp: string,
) {
    const generating = isGenerating(summary);

    useEffect(() => {
        if (!generating) {
            return;
        }

        const timer = window.setInterval(() => {
            router.reload({ only: [reloadProp] });
        }, AI_POLL_MS);

        return () => window.clearInterval(timer);
    }, [generating, reloadProp]);
}

/**
 * Un resumen con IA de una ficha (StructuredAiSummary de WeeklySync): el botón de generar o
 * regenerar, el estado (en cola, generando, error o atascado) y el resultado, en Markdown o con
 * `children` para los que traen una frase por persona o por cliente. Se guarda hasta que alguien lo
 * regenera (D-194).
 */
export function AiSummaryPanel({
    title,
    description,
    summary,
    action,
    actionData,
    reloadProp,
    generateLabel = t('weeklies.ai.generate'),
    children,
    headingLevel = 'h2',
    testId,
}: {
    title: string;
    description: string;
    summary: AiSummary | null;
    /** URL del POST que lo pide. */
    action: string;
    actionData?: Record<string, string>;
    /** Prop de Inertia que se recarga mientras se genera. */
    reloadProp: string;
    generateLabel?: string;
    /** Para los resúmenes con una frase por id: lo que se pinta con el resultado. */
    children?: ReactNode;
    headingLevel?: 'h2' | 'h3';
    testId?: string;
}) {
    const [processing, setProcessing] = useState(false);
    const generating = isGenerating(summary);
    const done = summary?.state === 'done';
    const Heading = headingLevel;

    useAiSummaryPolling(summary, reloadProp);

    const request = () =>
        router.post(action, actionData ?? {}, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
        });

    return (
        <section
            className="grid gap-3 border bg-card p-4"
            aria-busy={generating || processing}
            data-test={testId}
        >
            <header className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0 space-y-1">
                    <Heading className="flex items-center gap-2 text-base">
                        <Bot
                            aria-hidden="true"
                            className="size-4 text-primary"
                            strokeWidth={1.5}
                        />
                        {title}
                    </Heading>
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                </div>
                <Button
                    type="button"
                    variant={done ? 'outline' : 'default'}
                    size="sm"
                    onClick={request}
                    disabled={generating || processing}
                    data-test="ai-summary-generate"
                >
                    {generating || processing ? (
                        <Spinner />
                    ) : done ? (
                        <RefreshCw aria-hidden="true" />
                    ) : (
                        <Sparkles aria-hidden="true" />
                    )}
                    {generating
                        ? t('weeklies.ai.generating')
                        : summary?.state === 'failed' || summary?.stuck
                          ? t('weeklies.ai.retry')
                          : done
                            ? t('weeklies.ai.regenerate')
                            : generateLabel}
                </Button>
            </header>

            <div aria-live="polite" className="grid gap-3">
                {summary === null ? (
                    <p className="text-sm text-muted-foreground">
                        {t('weeklies.ai.empty')}
                    </p>
                ) : generating ? (
                    <p className="flex items-center gap-2 text-sm text-muted-foreground">
                        <Spinner />
                        {summary.state === 'queued'
                            ? t('weeklies.ai.queued')
                            : t('weeklies.ai.generating')}
                    </p>
                ) : summary.stuck ? (
                    <p className="flex items-center gap-2 text-sm text-warning">
                        <CircleAlert aria-hidden="true" className="size-4" />
                        {t('weeklies.ai.stuck')}
                    </p>
                ) : summary.state === 'failed' ? (
                    <p
                        role="alert"
                        className="flex items-center gap-2 text-sm text-danger"
                    >
                        <CircleAlert aria-hidden="true" className="size-4" />
                        {t('weeklies.ai.failed', {
                            error: summary.error ?? '',
                        })}
                    </p>
                ) : null}

                {done ? (
                    <>
                        {children ??
                            (summary?.content ? (
                                <SafeMarkdown
                                    source={summary.content}
                                    headingLevel={3}
                                    className="text-sm"
                                />
                            ) : null)}
                        <p className="text-xs text-muted-foreground">
                            {summary?.generated_at
                                ? summary.requested_by
                                    ? t('weeklies.ai.generated_by', {
                                          date: formatDateTime(
                                              summary.generated_at,
                                          ),
                                          name: summary.requested_by.name,
                                      })
                                    : t('weeklies.ai.generated_at', {
                                          date: formatDateTime(
                                              summary.generated_at,
                                          ),
                                      })
                                : null}{' '}
                            {t('weeklies.ai.notice')}
                        </p>
                    </>
                ) : null}
            </div>
        </section>
    );
}
