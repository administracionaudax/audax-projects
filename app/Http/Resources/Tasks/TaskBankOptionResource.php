<?php

namespace App\Http\Resources\Tasks;

use App\Models\HourBank;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bolsa en los selectores de tareas (contrato: resources/js/types/tasks.ts, TaskBankOption).
 * Sin datos económicos: el % de consumo lo ve cualquier interno (D-021). Cargar department antes.
 *
 * @mixin HourBank
 */
class TaskBankOptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status->value,
            'is_open' => $this->acceptsTime(),
            'department_id' => $this->department_id,
            'department' => $this->whenLoaded('department', fn () => $this->department === null ? null : [
                'id' => $this->department->id,
                'name' => $this->department->name,
                'color' => $this->department->color,
            ]),
            'consumed_pct' => $this->consumed_pct,
        ];
    }
}
