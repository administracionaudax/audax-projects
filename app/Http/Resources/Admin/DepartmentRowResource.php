<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\UserSummaryResource;
use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Departamento en /admin/departamentos. Contrato: resources/js/types/admin.ts (AdminDepartment).
 * Cargar managers y los recuentos (users_count de personas activas, inactive_users_count de las
 * que están de baja y open_hour_banks_count) antes.
 *
 * @mixin Department
 */
class DepartmentRowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $people = (int) $this->resource->getAttribute('users_count');
        $inactive = (int) $this->resource->getAttribute('inactive_users_count');
        $openBanks = (int) $this->resource->getAttribute('open_hour_banks_count');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'color' => $this->color,
            'managers' => UserSummaryResource::collection($this->whenLoaded('managers')),
            'users_count' => $people,
            'inactive_users_count' => $inactive,
            'open_hour_banks_count' => $openBanks,
            'can_delete' => $people === 0 && $inactive === 0 && $openBanks === 0,
        ];
    }
}
