import { Link } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { xsrfToken } from '@/lib/xsrf';
import { clientHistory } from '@/routes/team';
import { show as showCycle } from '@/routes/weeklies';
import type { WeeklyCycleRef } from '@/types/weeklies';

export type PersonClientReport = {
    id: number;
    cycle: WeeklyCycleRef;
    body: string;
    submitted_at: string;
    project: { id: number; code: string | null } | null;
};

type Page = { reports: PersonClientReport[]; next_page: number | null };

async function fetchPage(
    personId: number,
    clientId: number | null,
    page: number,
): Promise<Page> {
    const token = xsrfToken();
    const response = await fetch(
        clientHistory.url(
            { user: personId, client: clientId ?? 'general' },
            { query: { pagina: page } },
        ),
        {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(token ? { 'X-XSRF-TOKEN': token } : {}),
            },
        },
    );

    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }

    return (await response.json()) as Page;
}

/**
 * «Ver histórico» de una persona en un cliente (10.9b, D-233; `ws:TeamView.tsx:874-904`): todos sus
 * apuntes enviados sobre el cliente, del más reciente al más antiguo, de 25 en 25 con «Ver más».
 * Lo usan la ficha de persona (por cliente) y la pestaña Equipo del cliente (por persona).
 */
export function ClientHistoryDialog({
    person,
    client,
    open,
    onOpenChange,
}: {
    person: { id: number; name: string };
    client: { id: number; name: string } | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [reports, setReports] = useState<PersonClientReport[]>([]);
    const [next, setNext] = useState<number | null>(null);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const [failedPage, setFailedPage] = useState(1);
    const clientId = client?.id ?? null;
    // Solo vale la respuesta de la última petición: abrir el histórico de otro cliente enseguida
    // ya no mezcla la respuesta lenta del anterior (D-310).
    const request = useRef(0);

    const load = useCallback(
        async (page: number) => {
            const mine = ++request.current;
            setLoading(true);
            setFailed(false);

            try {
                const data = await fetchPage(person.id, clientId, page);

                if (mine !== request.current) {
                    return;
                }

                setReports((current) =>
                    page === 1 ? data.reports : [...current, ...data.reports],
                );
                setNext(data.next_page);
            } catch {
                if (mine === request.current) {
                    setFailed(true);
                    setFailedPage(page);
                }
            } finally {
                if (mine === request.current) {
                    setLoading(false);
                }
            }
        },
        [person.id, clientId],
    );

    useEffect(() => {
        if (open) {
            setReports([]);
            setNext(null);
            void load(1);
        }
    }, [open, load]);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="max-h-[85vh] overflow-y-auto sm:max-w-2xl"
                data-test="client-history-dialog"
            >
                <DialogHeader>
                    <DialogTitle>
                        {t('weeklies.client.team_history_title', {
                            name: person.name,
                        })}
                    </DialogTitle>
                    <DialogDescription>
                        {t('weeklies.client.team_history_description', {
                            client:
                                client?.name ?? t('weeklies.person.general'),
                        })}
                    </DialogDescription>
                </DialogHeader>
                <div
                    aria-live="polite"
                    aria-busy={loading}
                    className="grid gap-3"
                >
                    {reports.length === 0 && !loading && !failed ? (
                        <p className="text-sm text-muted-foreground">
                            {t('weeklies.client.team_history_empty')}
                        </p>
                    ) : null}
                    {reports.length > 0 ? (
                        <ul className="grid gap-3">
                            {reports.map((report) => (
                                <li
                                    key={report.id}
                                    className="grid gap-1 border bg-muted p-3 text-sm"
                                    data-test="client-history-report"
                                >
                                    <p className="flex flex-wrap justify-between gap-2 text-xs text-muted-foreground">
                                        <Link
                                            href={showCycle.url(
                                                report.cycle.id,
                                            )}
                                            className={cn(
                                                'font-medium text-foreground hover:underline',
                                                FOCUS_RING,
                                            )}
                                        >
                                            {report.cycle.label}
                                        </Link>
                                        <span>
                                            {report.project?.code
                                                ? `${report.project.code} · `
                                                : ''}
                                            {formatDate(report.submitted_at)}
                                        </span>
                                    </p>
                                    <p className="whitespace-pre-line">
                                        {report.body}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    ) : null}
                    {failed ? (
                        <div className="flex flex-wrap items-center gap-2">
                            <p role="alert" className="text-sm">
                                {t('weeklies.client.history_failed')}
                            </p>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => void load(failedPage)}
                            >
                                {t('weeklies.join.retry')}
                            </Button>
                        </div>
                    ) : null}
                    {loading ? (
                        <p className="flex items-center gap-2 text-sm text-muted-foreground">
                            <Spinner />
                            {t('weeklies.client.history_loading')}
                        </p>
                    ) : next !== null ? (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => void load(next)}
                            data-test="client-history-more"
                        >
                            {t('weeklies.client.history_more')}
                        </Button>
                    ) : null}
                </div>
            </DialogContent>
        </Dialog>
    );
}
