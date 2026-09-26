<?php

namespace App\Http\Resources\Time;

use Illuminate\Http\Resources\Json\JsonResource;
use JsonException;

/**
 * Convierte un Resource (y los Resources anidados, p. ej. UserSummaryResource dentro de
 * TimeEntryResource) en arrays planos, tal cual los define resources/js/types.
 *
 * Inertia serializa un JsonResource como respuesta HTTP y lo envuelve en {"data": …}, también si
 * va anidado dentro de otra prop: las páginas del área de horas reciben siempre datos planos.
 */
final class Plain
{
    /**
     * @return array<array-key, mixed>
     *
     * @throws JsonException
     */
    public static function of(JsonResource $resource): array
    {
        /** @var array<array-key, mixed> */
        return json_decode(json_encode($resource, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }
}
