<?php

namespace App\Domain\Reports\Delivery;

/**
 * Informes que se pueden exportar, enviar y programar (Fase 9, D-139). Cada uno corresponde a una
 * ruta de routes/app/reports.php (la weekly, de routes/app/weeklies.php, D-192); los parámetros de la ruta y los filtros de la URL viajan en
 * ReportRequest.
 */
enum ReportKind: string
{
    case Direction = 'direction';
    case Department = 'department';
    case Person = 'person';
    case Client = 'client';
    case Project = 'project';
    case Billing = 'billing';
    case Detail = 'detail';
    case Hours = 'hours';
    case ProjectHours = 'project_hours';
    case HourBank = 'hour_bank';
    case Weekly = 'weekly';
    /** Vendido frente a real (Fase 12, D-390). */
    case SoldVsActual = 'sold_vs_actual';

    public function routeName(): string
    {
        return match ($this) {
            self::Direction => 'reports.direction',
            self::Department => 'reports.department',
            self::Person => 'reports.person',
            self::Client => 'reports.client',
            self::Project => 'reports.project',
            self::Billing => 'reports.billing',
            self::Detail => 'reports.detail',
            self::Hours => 'reports.hours.export',
            self::ProjectHours => 'projects.time.export',
            self::HourBank => 'reports.hour-bank-pdf',
            self::Weekly => 'weeklies.report.pdf',
            self::SoldVsActual => 'reports.sold-vs-actual',
        };
    }
}
