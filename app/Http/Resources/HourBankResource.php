<?php

namespace App\Http\Resources;

use App\Domain\HourBanks\HourBankLedger;
use App\Models\HourBank;
use Illuminate\Http\Request;

/**
 * Contrato: resources/js/types/domain.ts (HourBank). Cargar department antes (N+1).
 * El % de consumo lo ve cualquier interno; los importes, solo con view-financials.
 *
 * @mixin HourBank
 */
class HourBankResource extends FinancialResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $financials = $this->canSeeFinancials($request);

        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'name' => $this->name,
            'department' => DepartmentResource::make($this->whenLoaded('department')),
            'department_id' => $this->department_id,
            'total_minutes' => $this->total_minutes,
            'consumed_minutes' => $this->consumed_minutes,
            'overage_minutes' => $this->overage_minutes,
            'remaining_minutes' => $this->remaining_minutes,
            'in_bank_minutes' => $this->in_bank_minutes,
            'consumed_pct' => $this->consumed_pct,
            'status' => $this->status->value,
            'overage_policy' => $this->overage_policy->value,
            'effective_overage_policy' => app(HourBankLedger::class)->effectivePolicy($this->resource)->value,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'renewed_from_id' => $this->renewed_from_id,
            'invoice_reference' => $this->invoice_reference,
            'notes' => $this->notes,
            'closed_at' => $this->closed_at?->toIso8601ZuluString(),
            'closed_remaining_minutes' => $this->closed_remaining_minutes,
            'hourly_rate' => $this->when($financials, $this->hourly_rate),
            'price_amount' => $this->when($financials, $this->price_amount),
        ];
    }
}
