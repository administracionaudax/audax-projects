<?php

namespace App\Models;

use App\Enums\WeeklyReminderChannel;
use App\Models\Concerns\LogsDomainActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Regla de recordatorio de la weekly (F-101 y F-102): canal, día ISO (1 = lunes) y hora "HH:MM" de
 * Madrid. La comprueba el programador cada 5 minutos (entrega 10.5).
 *
 * @property int $id
 * @property WeeklyReminderChannel $channel
 * @property int $day_of_week
 * @property string $time
 * @property bool $enabled
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['channel', 'day_of_week', 'time', 'enabled', 'position'])]
class WeeklyReminderRule extends Model
{
    use LogsDomainActivity;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'enabled' => true,
        'position' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => WeeklyReminderChannel::class,
            'day_of_week' => 'integer',
            'enabled' => 'boolean',
            'position' => 'integer',
        ];
    }
}
