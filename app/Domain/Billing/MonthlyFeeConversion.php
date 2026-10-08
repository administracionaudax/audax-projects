<?php

namespace App\Domain\Billing;

use App\Domain\Weeklies\Report\WeeklyProjectStatus;
use App\Enums\BillingType;
use App\Enums\HoldedDocumentKind;
use App\Models\HoldedInvoice;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Propuesta de conversión de los fees al tipo `monthly_fee` (Fase 12, D-382). ClickUp los importó
 * como «Por horas» con «Fee mensual de N h.» en la descripción y el código FE (D-135, D-188): se
 * proponen esos (por la descripción o por el código FE), con sus horas al mes (de la descripción o
 * del presupuesto) y, si hay facturas de Holded enlazadas, el importe de la última como importe al
 * mes. NUNCA se aplica sola: la orden app:convert-monthly-fees enseña la lista (--dry-run) y la
 * aplica al confirmarla.
 */
final class MonthlyFeeConversion
{
    /**
     * @return list<array{project: Project, minutes: int|null, amount: string|null, reason: string}>
     */
    public function candidates(): array
    {
        $projects = Project::query()
            ->whereIn('billing_type', [BillingType::TimeAndMaterials->value, BillingType::FixedPrice->value])
            ->whereNotNull('client_id')
            ->with('client')
            ->orderBy('code')
            ->get();

        $result = [];
        foreach ($projects as $project) {
            $byDescription = preg_match('/^\s*fee\s+mensual/iu', (string) $project->description) === 1;
            $byCode = WeeklyProjectStatus::hasFeeCode($project);
            if (! $byDescription && ! $byCode) {
                continue;
            }

            $result[] = [
                'project' => $project,
                'minutes' => WeeklyProjectStatus::feeBudget($project),
                'amount' => $this->lastInvoiceAmount($project),
                'reason' => $byDescription && $byCode ? 'description_and_code' : ($byDescription ? 'description' : 'code'),
            ];
        }

        return $result;
    }

    /**
     * Convierte los candidatos (o los de $codes). Devuelve cuántos.
     *
     * @param  list<string>|null  $codes
     */
    public function apply(?array $codes = null): int
    {
        $count = 0;

        DB::transaction(function () use ($codes, &$count): void {
            foreach ($this->candidates() as ['project' => $project, 'minutes' => $minutes, 'amount' => $amount]) {
                if ($codes !== null && ! in_array($project->code, $codes, true)) {
                    continue;
                }

                $project->billing_type = BillingType::MonthlyFee;
                $project->monthly_minutes ??= $minutes;
                $project->monthly_fee_amount ??= $amount;
                $project->save();
                $count++;
            }
        });

        return $count;
    }

    /** Base de la última factura de Holded enlazada con el proyecto (sin rectificativas ni anuladas). */
    private function lastInvoiceAmount(Project $project): ?string
    {
        $invoice = HoldedInvoice::query()
            ->where('kind', HoldedDocumentKind::Invoice->value)
            ->where('is_draft', false)
            ->where('collection_status', '!=', 'cancelled')
            ->whereHas('links', fn ($query) => $query->where('project_id', $project->id))
            ->orderByDesc('issued_on')->orderByDesc('id')
            ->first(['id', 'subtotal']);

        return $invoice === null ? null : (string) $invoice->subtotal;
    }
}
