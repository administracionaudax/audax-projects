<?php

namespace App\Models\Concerns;

use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Auditoría de los cambios (SPEC §4.6 y §15): quién, qué, antes y después y cuándo.
 * Obligatoria en Project, HourBank, Task y TimeEntry; también en Client y TimesheetPeriod.
 * Cada modelo puede excluir columnas ruidosas con ACTIVITY_EXCEPT.
 */
trait LogsDomainActivity
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logExcept(['created_at', 'updated_at', ...static::activityExcept()])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName($this->getTable());
    }

    /**
     * @return list<string>
     */
    protected static function activityExcept(): array
    {
        return [];
    }
}
