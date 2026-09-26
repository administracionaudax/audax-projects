<?php

namespace App\Http\Resources\Tasks;

use Illuminate\Http\Resources\Json\JsonResource;
use JsonException;

/**
 * Convierte Resources en arrays planos para las props de Inertia.
 *
 * Inertia trata un JsonResource como Responsable y lo envuelve en `data` (también los anidados,
 * p. ej. el `assignee` de TaskResource dentro de un ->resolve()). Los tipos de
 * resources/js/types no llevan ese envoltorio, así que las páginas de tareas los pasan por aquí.
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
        return json_decode(json_encode($resource, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR) ?? [];
    }
}
