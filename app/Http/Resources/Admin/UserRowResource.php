<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\DepartmentResource;
use App\Http\Resources\FinancialResource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Persona en la administración de usuarios. Contrato: resources/js/types/admin.ts (AdminUser).
 * Cargar department y roles antes (N+1); last_login_at llega como subconsulta del controlador.
 * Coste/hora y tarifa solo con view-financials.
 *
 * @mixin User
 */
class UserRowResource extends FinancialResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $financials = $this->canSeeFinancials($request);
        $lastLogin = $this->resource->getAttribute('last_login_at');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar' => $this->avatar_url,
            'role' => $this->getRoleNames()->first(),
            'department_id' => $this->department_id,
            'department' => DepartmentResource::make($this->whenLoaded('department')),
            'is_active' => $this->is_active,
            'last_login_at' => $lastLogin !== null ? CarbonImmutable::parse((string) $lastLogin, 'UTC')->toIso8601ZuluString() : null,
            'two_factor_enabled' => $this->two_factor_confirmed_at !== null,
            'hourly_cost' => $this->when($financials, fn () => $this->hourly_cost),
            'default_hourly_rate' => $this->when($financials, fn () => $this->default_hourly_rate),
        ];
    }
}
