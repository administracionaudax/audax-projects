<?php

namespace App\Http\Resources\Tasks;

use App\Models\HourBank;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bolsa en los selectores de tareas (contrato: resources/js/types/tasks.ts, TaskBankOption).
 * Sin datos económicos: el % de consumo lo ve cualquier interno (D-021), salvo un colaborador
 * externo, que recibe null (D-134). Cargar department antes.
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
            'consumed_pct' => self::hidesConsumption($request) ? null : $this->consumed_pct,
        ];
    }

    public static function hidesConsumption(Request $request): bool
    {
        $viewer = $request->user();

        return $viewer instanceof User && $viewer->isCollaborator();
    }
}
