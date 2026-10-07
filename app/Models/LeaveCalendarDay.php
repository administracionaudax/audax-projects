<?php

namespace App\Models;

use App\Enums\LeaveCalendarDayKind;
use App\Models\Concerns\LogsDomainActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Día especial del calendario laboral (Fase 11, R3; W-034 y W-039; D-366): media jornada (la
 * jornada teórica es la mitad, Capacity) o días bloqueados para las vacaciones. Lo gestiona
 * RR. HH. en /ausencias/calendario; queda en la auditoría.
 *
 * @property int $id
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property LeaveCalendarDayKind $kind
 * @property string $name
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['start_date', 'end_date', 'kind', 'name', 'created_by'])]
class LeaveCalendarDay extends Model
{
    use LogsDomainActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'kind' => LeaveCalendarDayKind::class,
        ];
    }
}
