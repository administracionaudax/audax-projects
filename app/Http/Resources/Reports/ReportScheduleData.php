<?php

namespace App\Http\Resources\Reports;

use App\Models\ReportDelivery;
use App\Models\ReportSchedule;
use App\Models\User;
use App\Support\LocalTime;

/**
 * Contrato JSON de los envíos programados y su historial (D-141) con
 * resources/js/types/report-deliveries.ts. Instantes en ISO 8601 (UTC); la fecha y la hora de
 * «una vez», en hora de Madrid, para el formulario.
 */
final class ReportScheduleData
{
    /**
     * @return array<string, mixed>
     */
    public static function row(ReportSchedule $schedule, ?ReportDelivery $last = null): array
    {
        $runAt = $schedule->run_at?->setTimezone(LocalTime::timezone());

        return [
            'id' => $schedule->id,
            'title' => $schedule->title,
            'kind' => $schedule->request['kind'],
            'owner' => ['id' => $schedule->owner->id, 'name' => $schedule->owner->name],
            'formats' => $schedule->formats,
            'relative_period' => $schedule->relative_period->value,
            'frequency' => $schedule->frequency->value,
            'run_date' => $runAt?->toDateString(),
            'weekday' => $schedule->weekday,
            'month_day' => $schedule->month_day,
            'time' => $schedule->time,
            'recipient_count' => count($schedule->recipient_user_ids) + count($schedule->recipient_emails),
            'external_count' => count($schedule->recipient_emails),
            'is_active' => $schedule->is_active,
            'paused_reason' => $schedule->paused_reason?->value,
            'paused_reason_label' => $schedule->paused_reason?->label(),
            'next_run_at' => $schedule->next_run_at?->toIso8601ZuluString(),
            'last_run_at' => $schedule->last_run_at?->toIso8601ZuluString(),
            'last_status' => $last?->status->value,
        ];
    }

    /**
     * Detalle: la fila, el informe y los destinatarios (para editar) y el historial.
     *
     * @param  list<ReportDelivery>  $deliveries  del más reciente al más antiguo
     * @return array<string, mixed>
     */
    public static function detail(ReportSchedule $schedule, array $deliveries): array
    {
        $names = User::query()->whereKey($schedule->recipient_user_ids)->pluck('name', 'id');

        return self::row($schedule, $deliveries[0] ?? null) + [
            'request' => $schedule->reportRequest()->toArray(),
            'recipient_user_ids' => $schedule->recipient_user_ids,
            'recipient_users' => array_map(
                fn (int $id): array => ['id' => $id, 'name' => (string) ($names[$id] ?? '—')],
                $schedule->recipient_user_ids,
            ),
            'recipient_emails' => $schedule->recipient_emails,
            'subject' => $schedule->subject,
            'message' => $schedule->message,
            'deliveries' => array_map(fn (ReportDelivery $delivery): array => self::delivery($delivery), $deliveries),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function delivery(ReportDelivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'created_at' => $delivery->created_at?->toIso8601ZuluString(),
            'sent_at' => $delivery->sent_at?->toIso8601ZuluString(),
            'status' => $delivery->status->value,
            'error' => $delivery->error,
            'formats' => $delivery->formats,
            'recipient_count' => $delivery->recipientCount(),
            'external_count' => count($delivery->recipient_emails),
            'recipient_emails' => $delivery->recipient_emails,
        ];
    }
}
