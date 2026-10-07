<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Aviso del registro ya enviado (D-339): uno por persona, día de Madrid y tipo (`clock_in`,
 * `clock_out` o `unclosed`). Se reclama con una inserción única antes de enviar.
 *
 * @property int $id
 * @property int $user_id
 * @property CarbonImmutable $date
 * @property string $kind
 * @property CarbonImmutable $sent_at
 */
class ClockReminder extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'sent_at' => 'immutable_datetime',
        ];
    }
}
