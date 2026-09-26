<?php

namespace App\Models;

use App\Enums\ProjectAlert;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivote project_members (SPEC §4.2, D-005, D-023): miembros, gestores y sus alertas.
 *
 * @property int $project_id
 * @property int $user_id
 * @property bool $is_manager
 * @property array<string, bool>|null $alert_preferences
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ProjectMember extends Pivot
{
    protected $table = 'project_members';

    /**
     * Un cambio de miembros o gestores vacía la memoria de pertenencia de la petición (PERF-04).
     * Con ->using(ProjectMember::class), attach, sync, updateExistingPivot y detach disparan estos
     * eventos.
     */
    protected static function booted(): void
    {
        static::saved(fn () => User::forgetMemberships());
        static::deleted(fn () => User::forgetMemberships());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_manager' => 'boolean',
            'alert_preferences' => 'array',
        ];
    }

    /**
     * ¿Quiere este gestor recibir la alerta? Por defecto, sí (D-023).
     */
    public function wantsAlert(ProjectAlert $alert): bool
    {
        return (bool) ($this->alert_preferences[$alert->value] ?? true);
    }
}
