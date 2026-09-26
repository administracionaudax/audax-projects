<?php

namespace App\Http\Resources\Clients;

use App\Http\Resources\HourBankResource;
use App\Models\HourBank;
use Illuminate\Http\Request;

/**
 * Bolsa en la ficha de cliente: HourBankResource más su proyecto y las horas comprometidas de sus
 * tareas abiertas. Contrato: resources/js/types/clients.ts (ClientHourBank). Cargar department y
 * project antes; committed_minutes llega del controlador.
 *
 * @mixin HourBank
 */
class ClientHourBankResource extends HourBankResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'project' => $this->whenLoaded('project', fn () => [
                'id' => $this->project->id,
                'code' => $this->project->code,
                'name' => $this->project->name,
                'color' => $this->project->color,
            ]),
            'committed_minutes' => (int) $this->resource->getAttribute('committed_minutes'),
        ];
    }
}
