<?php

namespace App\Jobs;

use App\Domain\Reports\Delivery\ReportDeliverer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Genera y envía por correo un informe (D-141) en la cola `mail`, con los permisos de quien lo
 * envía (ReportDeliverer). Un solo intento: repetirlo podría mandar el correo dos veces a quien
 * ya lo recibió; si falla, queda «Fallido» en el historial y se puede «Enviar ahora» de nuevo.
 */
class SendReportDelivery implements ShouldQueue
{
    use Queueable;

    /** Segundos como máximo (la conversión a PDF tiene su propio límite, D-140). */
    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public readonly int $deliveryId)
    {
        $this->onQueue('mail');
    }

    public function handle(ReportDeliverer $deliverer): void
    {
        $deliverer->deliver($this->deliveryId);
    }

    public function failed(?Throwable $exception): void
    {
        app(ReportDeliverer::class)->failed($this->deliveryId, $exception);
    }
}
