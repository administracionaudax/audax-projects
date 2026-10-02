<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Plantilla de proyecto (SPEC §4.3). `structure`:
 *   {tasks: [{ref, title, parent_ref?, task_type_id?, estimated_minutes?, priority?, is_milestone?,
 *             start_offset_days?, duration_days?}],
 *    dependencies: [{from_ref, to_ref}]}
 * Los offsets son días naturales desde el inicio del proyecto; la entrega = inicio + duración − 1.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property array{tasks: list<array<string, mixed>>, dependencies?: list<array{from_ref: string, to_ref: string}>} $structure
 * @property bool $is_active
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
#[Fillable(['name', 'description', 'structure', 'is_active', 'created_by'])]
class ProjectTemplate extends Model
{
    use LogsDomainActivity, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'structure' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
