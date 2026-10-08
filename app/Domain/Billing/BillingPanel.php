<?php

namespace App\Domain\Billing;

use App\Domain\Reports\ReportFilters;
use App\Models\Client;
use App\Models\HoldedInvoice;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * El «Vendido frente a real» de una ficha (Fase 12, D-392): proyecto (pestaña Facturación), cliente
 * (/clientes/{id}/facturacion) y bolsa (su detalle). Las unidades salen de SoldVsActual con la ficha
 * fijada; las facturas enlazadas, solo con view-billing.
 */
final class BillingPanel
{
    /** Facturas que se enseñan en una ficha (el resto, en el listado filtrado). */
    public const int INVOICES = 30;

    public function __construct(private readonly SoldVsActual $report) {}

    /**
     * Una bolsa, entera (de su inicio a hoy o a su fin).
     *
     * @return array<string, mixed>
     */
    public function forBank(HourBank $bank, User $viewer): array
    {
        $to = $bank->end_date ?? LocalTime::today();
        $filters = ReportFilters::fromQuery(['periodo' => 'rango', 'desde' => $bank->start_date->toDateString(), 'hasta' => $to->max($bank->start_date)->toDateString()]);
        $query = (new SoldVsActualQuery($filters))->fixed([$bank->project_id], [$bank->id]);

        return $this->panel($query, $viewer, fn (Builder $invoices) => $invoices->whereHas('links', fn (Builder $link) => $link->where('hour_bank_id', $bank->id)));
    }

    /**
     * @return array<string, mixed>
     */
    public function forProject(Project $project, User $viewer, SoldVsActualQuery $query): array
    {
        return $this->panel($query->fixed([$project->id]), $viewer, fn (Builder $invoices) => $invoices->whereHas('links', fn (Builder $link) => $link->where('project_id', $project->id)));
    }

    /**
     * @return array<string, mixed>
     */
    public function forClient(Client $client, User $viewer, SoldVsActualQuery $query): array
    {
        $query = new SoldVsActualQuery($query->filters->with(['clientIds' => [$client->id]]), $query->kinds, $query->managerId);

        return $this->panel($query, $viewer, fn (Builder $invoices) => $invoices->where('client_id', $client->id));
    }

    /**
     * @param  callable(Builder<HoldedInvoice>): mixed  $invoiceScope
     * @return array<string, mixed>
     */
    private function panel(SoldVsActualQuery $query, User $viewer, callable $invoiceScope): array
    {
        $financials = Gate::forUser($viewer)->allows('view-billing');
        $report = $this->report->report($query, $viewer, $financials);

        $invoices = null;
        $invoiceCount = 0;
        if ($financials) {
            $builder = HoldedInvoice::query();
            $invoiceScope($builder);
            $invoiceCount = (clone $builder)->count();
            $invoices = $builder->with(['client:id,name', 'links.project:id,code,name', 'links.hourBank:id,name'])
                ->orderByDesc('issued_on')->orderByDesc('id')->limit(self::INVOICES)->get()
                ->map(fn (HoldedInvoice $invoice): array => InvoicePresenter::summary($invoice))->values()->all();
        }

        return [
            'report' => $report,
            'invoices' => $invoices,
            'invoice_count' => $invoiceCount,
        ];
    }
}
