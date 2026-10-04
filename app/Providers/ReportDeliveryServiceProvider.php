<?php

namespace App\Providers;

use App\Domain\Reports\Delivery\ReportFileGenerator;
use App\Domain\Reports\Delivery\UnavailableReportFileGenerator;
use Illuminate\Support\ServiceProvider;

/**
 * Envío de informes por correo y envíos programados (Fase 9, D-141). El generador real de
 * ReportFileGenerator lo registra la entrega 9.2; aquí solo hay un respaldo con bindIf, en boot()
 * (cuando todos los register() ya han corrido), para que nunca tape al real.
 */
class ReportDeliveryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->bindIf(ReportFileGenerator::class, UnavailableReportFileGenerator::class);
    }
}
