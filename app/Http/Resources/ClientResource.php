<?php

namespace App\Http\Resources;

use App\Models\Client;
use Illuminate\Http\Request;

/**
 * Contrato: resources/js/types/domain.ts (Client).
 *
 * @mixin Client
 */
class ClientResource extends FinancialResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'tax_id' => $this->tax_id,
            'contact_name' => $this->contact_name,
            'contact_email' => $this->contact_email,
            'phone' => $this->phone,
            'notes' => $this->notes,
            'is_active' => $this->is_active,
            'default_hourly_rate' => $this->when($this->canSeeFinancials($request), $this->default_hourly_rate),
            'projects_count' => $this->whenCounted('projects'),
        ];
    }
}
