<?php

namespace App\Domain\Reports\Delivery;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Auditoría de los envíos de informes (D-139, D-141): log `report-delivery` en activity_log.
 * Eventos: report_sent, report_send_failed, report_skipped y report_downloaded (cada envío y cada
 * descarga de un enlace) y schedule_created, schedule_updated, schedule_paused, schedule_resumed y
 * schedule_deleted (las programaciones). Los correos externos quedan en las propiedades.
 */
final class DeliveryAudit
{
    public const string LOG = 'report-delivery';

    /**
     * @param  array<string, mixed>  $properties
     */
    public static function record(string $event, Model $subject, ?User $causer, array $properties = []): void
    {
        $activity = activity(self::LOG)->performedOn($subject)->event($event)->withProperties($properties);

        if ($causer !== null) {
            $activity->causedBy($causer);
        }

        $activity->log("report_delivery.{$event}");
    }
}
