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
        self::ensureMemory();

        $deliverer->deliver($this->deliveryId);
    }

    /**
     * El worker de la cola `mail` (supervisor-mail de config/horizon.php) tiene 256 MB para generar
     * el PDF y el Excel, pero hereda el memory_limit de la CLI del servidor (128M): se sube hasta
     * esos 256 MB. Nunca se baja, y sin límite (-1) se deja como está.
     */
    public static function ensureMemory(): void
    {
        $wanted = (int) config('horizon.defaults.supervisor-mail.memory', 256) * 1024 * 1024;
        $current = self::bytes((string) ini_get('memory_limit'));

        if ($current >= 0 && $current < $wanted) {
            ini_set('memory_limit', (string) ($wanted / 1024 / 1024).'M');
        }
    }

    /** «128M» → bytes; -1 si no hay límite. */
    private static function bytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    public function failed(?Throwable $exception): void
    {
        app(ReportDeliverer::class)->failed($this->deliveryId, $exception);
    }
}
