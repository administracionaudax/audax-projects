<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Proyecto de Holded (Fase 12, D-388): si su nombre lleva el código de un proyecto de Audax
 * («ACME-FE1 · Mantenimiento») se enlaza solo; si no, a mano. Las líneas de factura con ese
 * proyecto enlazan la factura con el de Audax.
 *
 * @property int $id
 * @property string $holded_id
 * @property string $name
 * @property int|null $project_id
 * @property bool $linked_manually
 * @property CarbonImmutable|null $synced_at
 * @property-read Project|null $project
 */
#[Fillable(['holded_id', 'name', 'project_id', 'linked_manually', 'synced_at'])]
class HoldedProject extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'linked_manually' => 'boolean',
            'synced_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }
}
