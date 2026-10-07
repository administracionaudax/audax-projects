<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Punto de partida de la cadena de una persona tras la supresión del mes 49 (Fase 11, R2; D-348):
 * la última fila suprimida (`seq` y su huella), para que RegisterIntegrity siga comprobando la
 * cadena desde ahí y ClockWriter siga numerando sin repetir.
 *
 * @property int $id
 * @property int $user_id
 * @property int $seq
 * @property string $hash
 * @property CarbonImmutable $pruned_through
 * @property CarbonImmutable|null $updated_at
 */
class RegisterCheckpoint extends Model
{
    public const CREATED_AT = null;

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'pruned_through' => 'date:Y-m-d',
        ];
    }
}
