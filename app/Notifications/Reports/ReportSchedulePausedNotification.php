<?php

namespace App\Notifications\Reports;

use App\Domain\Reports\Delivery\PauseReason;
use App\Notifications\AppNotification;

/**
 * Un envío programado se ha pausado solo (D-141): su propietario está desactivado, ya no puede
 * ver el informe o no le quedan destinatarios. Llega al propietario (si sigue activo) y a los
 * admins, en la campana y por email (evento reports.schedule_paused). Lleva al detalle del envío,
 * desde donde se puede editar o reanudar.
 */
class ReportSchedulePausedNotification extends AppNotification
{
    public function __construct(
        public readonly int $scheduleId,
        public readonly string $scheduleTitle,
        public readonly string $ownerName,
        public readonly PauseReason $reason,
    ) {}

    public function kind(): string
    {
        return 'reports.schedule_paused';
    }

    public function title(object $notifiable): string
    {
        return $this->text('report_deliveries.notifications.paused.title', ['title' => $this->scheduleTitle]);
    }

    public function body(object $notifiable): ?string
    {
        return $this->text('report_deliveries.notifications.paused.body', [
            'owner' => $this->ownerName,
            'reason' => $this->reason->label(),
        ]);
    }

    public function url(object $notifiable): ?string
    {
        return '/informes/envios/'.$this->scheduleId;
    }

    public function icon(): ?string
    {
        return 'triangle-alert';
    }

    /**
     * @param  array<string, string>  $replace
     */
    private function text(string $key, array $replace): string
    {
        $text = __($key, $replace);

        return is_string($text) ? $text : $key;
    }
}
