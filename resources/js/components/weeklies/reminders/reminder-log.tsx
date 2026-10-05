import { router } from '@inertiajs/react';
import { useId } from 'react';
import { NativeSelect } from '@/components/admin/native-select';
import { ListPagination } from '@/components/projects-list/list-pagination';
import { Label } from '@/components/ui/label';
import { channelLabel } from '@/components/weeklies/reminders/reminder-rules-editor';
import { formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { edit } from '@/routes/weeklies/reminders';
import type {
    WeeklyReminderLogRow,
    WeeklyReminderStatus,
    WeeklyReminderTemplate,
    WeeklyRemindersPageProps,
} from '@/types/weeklies';

const TEMPLATES: WeeklyReminderTemplate[] = [
    'automatic',
    'manual',
    'friday',
    'weekly_closed',
    'deadline',
];

const STATUSES: WeeklyReminderStatus[] = [
    'queued',
    'sent',
    'failed',
    'skipped',
];

/** Tono de cada estado: siempre con texto, nunca solo color. */
const STATUS_TONE: Record<WeeklyReminderStatus, string> = {
    queued: 'bg-neutral-soft',
    sent: 'bg-success-soft',
    failed: 'bg-danger-soft',
    skipped: 'bg-warning-soft',
};

export function ReminderStatus({ status }: { status: WeeklyReminderStatus }) {
    return (
        <span
            className={cn(
                'inline-flex px-1.5 py-0.5 text-xs',
                STATUS_TONE[status],
            )}
        >
            {t(`weekly_reminders.statuses.${status}`)}
        </span>
    );
}

/**
 * Registro de envíos (F-108, el email_log de WeeklySync): cada aviso con la persona, el aviso, la
 * semana, el canal y el estado (con el motivo si se omitió o el error si falló). Filtros por aviso y
 * estado en la URL (?plantilla=&estado=) y páginas de 50. En el móvil, tarjetas.
 */
export function ReminderLog({
    logs,
    filters,
}: Pick<WeeklyRemindersPageProps, 'logs' | 'filters'>) {
    const id = useId();
    const filtered = filters.template !== null || filters.status !== null;

    const apply = (next: Partial<WeeklyRemindersPageProps['filters']>) => {
        const merged = { ...filters, ...next };

        router.get(
            edit.url({
                query: {
                    plantilla: merged.template ?? undefined,
                    estado: merged.status ?? undefined,
                },
            }),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                only: ['logs', 'filters'],
            },
        );
    };

    return (
        <div className="grid gap-4">
            <div className="flex flex-wrap gap-4">
                <div className="grid gap-1">
                    <Label htmlFor={`${id}-template`}>
                        {t('weekly_reminders.log.filter_template')}
                    </Label>
                    <NativeSelect
                        id={`${id}-template`}
                        className="w-56"
                        value={filters.template ?? ''}
                        onChange={(event) =>
                            apply({
                                template:
                                    (event.target
                                        .value as WeeklyReminderTemplate) ||
                                    null,
                            })
                        }
                    >
                        <option value="">
                            {t('weekly_reminders.log.all')}
                        </option>
                        {TEMPLATES.map((template) => (
                            <option key={template} value={template}>
                                {t(
                                    `weekly_reminders.templates_names.${template}`,
                                )}
                            </option>
                        ))}
                    </NativeSelect>
                </div>
                <div className="grid gap-1">
                    <Label htmlFor={`${id}-status`}>
                        {t('weekly_reminders.log.filter_status')}
                    </Label>
                    <NativeSelect
                        id={`${id}-status`}
                        className="w-44"
                        value={filters.status ?? ''}
                        onChange={(event) =>
                            apply({
                                status:
                                    (event.target
                                        .value as WeeklyReminderStatus) || null,
                            })
                        }
                    >
                        <option value="">
                            {t('weekly_reminders.log.all')}
                        </option>
                        {STATUSES.map((status) => (
                            <option key={status} value={status}>
                                {t(`weekly_reminders.statuses.${status}`)}
                            </option>
                        ))}
                    </NativeSelect>
                </div>
            </div>

            {logs.data.length === 0 ? (
                <p
                    className="border border-dashed p-4 text-sm text-muted-foreground"
                    data-test="reminder-log-empty"
                >
                    {filtered
                        ? t('weekly_reminders.log.empty_filtered')
                        : t('weekly_reminders.log.empty')}
                </p>
            ) : (
                <>
                    <div className="hidden overflow-x-auto md:block">
                        <table
                            className="w-full text-sm"
                            data-test="reminder-log"
                        >
                            <thead className="border-b text-left text-muted-foreground">
                                <tr>
                                    <th
                                        scope="col"
                                        className="py-2 pr-3 font-normal"
                                    >
                                        {t('weekly_reminders.log.when')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="py-2 pr-3 font-normal"
                                    >
                                        {t('weekly_reminders.log.person')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="py-2 pr-3 font-normal"
                                    >
                                        {t('weekly_reminders.log.notice')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="py-2 pr-3 font-normal"
                                    >
                                        {t('weekly_reminders.log.week')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="py-2 pr-3 font-normal"
                                    >
                                        {t('weekly_reminders.log.channel')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="py-2 font-normal"
                                    >
                                        {t('weekly_reminders.log.status')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {logs.data.map((row) => (
                                    <tr
                                        key={row.id}
                                        data-test="reminder-log-row"
                                    >
                                        <td className="py-2 pr-3 align-top whitespace-nowrap">
                                            {formatDateTime(row.created_at)}
                                        </td>
                                        <td className="py-2 pr-3 align-top">
                                            <span className="block">
                                                {row.recipient_name ?? '—'}
                                            </span>
                                            <span className="block text-xs break-all text-muted-foreground">
                                                {row.recipient_email}
                                            </span>
                                        </td>
                                        <td className="py-2 pr-3 align-top">
                                            <Notice row={row} />
                                        </td>
                                        <td className="py-2 pr-3 align-top">
                                            {row.cycle_number ?? '—'}
                                        </td>
                                        <td className="py-2 pr-3 align-top">
                                            {channelLabel(row.channel)}
                                        </td>
                                        <td className="py-2 align-top">
                                            <ReminderStatus
                                                status={row.status}
                                            />
                                            {row.error ? (
                                                <span className="mt-1 block text-xs text-muted-foreground">
                                                    {row.error}
                                                </span>
                                            ) : null}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <ul className="grid gap-3 md:hidden">
                        {logs.data.map((row) => (
                            <li
                                key={row.id}
                                className="grid gap-1 border bg-card p-3 text-sm"
                                data-test="reminder-log-card"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="font-medium">
                                        {row.recipient_name ?? '—'}
                                    </span>
                                    <ReminderStatus status={row.status} />
                                </div>
                                <Notice row={row} />
                                <span className="text-muted-foreground">
                                    {[
                                        row.cycle_number,
                                        channelLabel(row.channel),
                                        formatDateTime(row.created_at),
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </span>
                                {row.error ? (
                                    <span className="text-xs text-muted-foreground">
                                        {row.error}
                                    </span>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                </>
            )}

            <ListPagination
                page={logs}
                label={t('weekly_reminders.log.pages')}
            />
        </div>
    );
}

function Notice({ row }: { row: WeeklyReminderLogRow }) {
    return (
        <span className="block">
            {t(`weekly_reminders.templates_names.${row.template}`)}
            <span className="block text-xs text-muted-foreground">
                {row.sent_by_name
                    ? t('weekly_reminders.log.by', { name: row.sent_by_name })
                    : t('weekly_reminders.log.automatic')}
            </span>
        </span>
    );
}
