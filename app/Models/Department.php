<?php

namespace App\Models;

use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Departamento (SPEC §4.1). Puede tener varios responsables con los mismos permisos (D-024).
 *
 * @property int $id
 * @property string $name
 * @property string $color
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, User> $managers
 */
#[Fillable(['name', 'color'])]
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Departamentos por defecto que crea app:install, con colores de la paleta de marca (SPEC §3.1).
     */
    public const array DEFAULTS = [
        ['name' => 'Diseño', 'color' => '#0171FF'],
        ['name' => 'Desarrollo', 'color' => '#179FA5'],
        ['name' => 'Marketing', 'color' => '#5E2DAD'],
    ];

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Responsables del departamento (pivote department_managers).
     *
     * @return BelongsToMany<User, $this>
     */
    public function managers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'department_managers')->withTimestamps();
    }
}
