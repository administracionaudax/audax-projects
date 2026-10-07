<?php

namespace App\Models;

use App\Domain\People\RegisterImmutable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Ancla diaria del registro (Fase 11, R2; PLAN-FASE-11 §11.1.4; D-352): la última fila de la cadena
 * de cada persona (`heads`: id de persona → seq y huella) y un resumen encadenado con el del día
 * anterior (`digest`). Con ella, ni quien tenga acceso a la base de datos puede reescribir el
 * pasado y recalcular todas las huellas sin que se note: el ancla guardada (y copiada en las
 * exportaciones y la copia nocturna) ya no coincide. Solo alta. Lo escribe RegisterAnchors.
 *
 * @property int $id
 * @property CarbonImmutable $date
 * @property array<int, array{seq: int, hash: string}> $heads
 * @property int $events_count
 * @property string $prev_digest
 * @property string $digest
 * @property bool $verified_ok
 * @property list<string>|null $problems
 * @property CarbonImmutable|null $created_at
 */
class RegisterAnchor extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'heads' => 'array',
            'events_count' => 'integer',
            'verified_ok' => 'boolean',
            'problems' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw RegisterImmutable::append('cambiar'));
        static::deleting(fn (): never => throw RegisterImmutable::append('borrar'));
    }
}
