<?php

namespace App\Http\Resources;

use App\Models\Project;
use Illuminate\Http\Request;

/**
 * Contrato: resources/js/types/domain.ts (Project). Cargar client y owner antes (N+1).
 *
 * @mixin Project
 */
class ProjectResource extends FinancialResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $financials = $this->canSeeFinancials($request);

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'color' => $this->color,
            'description' => $this->description,
            'client' => $this->whenLoaded('client', fn () => $this->client === null ? null : [
                'id' => $this->client->id,
                'name' => $this->client->name,
            ]),
            'client_id' => $this->client_id,
            'billing_type' => $this->billing_type->value,
            'status' => $this->status->value,
            'start_date' => $this->start_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            // El presupuesto de horas no es para un colaborador externo (D-134).
            'budget_minutes' => $request->user()?->isCollaborator() ? null : $this->budget_minutes,
            // Fee mensual (Fase 12, D-382): horas al mes y, con view-financials, el importe al mes.
            'monthly_minutes' => $request->user()?->isCollaborator() ? null : $this->monthly_minutes,
            'fixed_price_amount' => $this->when($financials, $this->fixed_price_amount),
            'monthly_fee_amount' => $this->when($financials, $this->monthly_fee_amount),
            'hourly_rate' => $this->when($financials, $this->hourly_rate),
            'owner' => UserSummaryResource::make($this->whenLoaded('owner')),
            'owner_user_id' => $this->owner_user_id,
            'is_internal' => $this->isInternal(),
        ];
    }
}
