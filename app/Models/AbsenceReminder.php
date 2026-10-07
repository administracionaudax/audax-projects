<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Un aviso de ausencias que ya salió (Fase 11, R3; D-369): el saldo que está a punto de caducar o
 * el justificante pendiente. Uno por clave, para no repetirlo.
 *
 * @property int $id
 * @property string $kind
 * @property string $key
 * @property int $user_id
 * @property CarbonImmutable $sent_at
 */
class AbsenceReminder extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sent_at' => 'immutable_datetime'];
    }
}
