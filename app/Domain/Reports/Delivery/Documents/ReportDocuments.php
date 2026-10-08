<?php

namespace App\Domain\Reports\Delivery\Documents;

use App\Domain\Reports\Delivery\ReportKind;
use Illuminate\Contracts\Container\Container;

/**
 * El documento (ReportDocument) de cada informe exportable (ReportKind, D-139).
 */
final class ReportDocuments
{
    public function __construct(private readonly Container $container) {}

    public function for(ReportKind $kind): ReportDocument
    {
        /** @var ReportDocument */
        return $this->container->make(match ($kind) {
            ReportKind::Direction => DirectionDocument::class,
            ReportKind::Department => DepartmentDocument::class,
            ReportKind::Person => PersonDocument::class,
            ReportKind::Client => ClientDocument::class,
            ReportKind::Project => ProjectDocument::class,
            ReportKind::Billing => BillingDocument::class,
            ReportKind::Detail => DetailDocument::class,
            ReportKind::Hours => HoursDocument::class,
            ReportKind::ProjectHours => ProjectHoursDocument::class,
            ReportKind::HourBank => HourBankDocument::class,
            ReportKind::Weekly => WeeklyDocument::class,
            ReportKind::SoldVsActual => SoldVsActualDocument::class,
            ReportKind::Invoicing => InvoicingDocument::class,
        });
    }
}
