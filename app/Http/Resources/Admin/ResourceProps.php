<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use JsonException;

/**
 * Convierte un Resource en props de Inertia tal y como llegarían en JSON, sin la envoltura «data».
 *
 * Inertia resuelve cada prop que es Responsable con toResponse(), y eso envuelve en «data» también
 * los Resources anidados que quedan dentro de un array ya resuelto (p. ej. los responsables de un
 * departamento). Serializar a JSON aplica jsonSerialize() en cascada, sin envolturas, y deja fuera
 * los campos condicionales (los datos económicos sin view-financials no llegan al navegador).
 * Los listados paginados se pasan tal cual: su toResponse() ya da {data, links, meta}.
 */
final class ResourceProps
{
    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public static function item(JsonResource $resource, Request $request): array
    {
        /** @var array<string, mixed> */
        return self::plain($resource->resolve($request));
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws JsonException
     */
    public static function list(JsonResource $collection, Request $request): array
    {
        /** @var list<array<string, mixed>> */
        return array_values(self::plain($collection->resolve($request)));
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     *
     * @throws JsonException
     */
    private static function plain(array $data): array
    {
        /** @var array<array-key, mixed> */
        return json_decode(json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, 512, JSON_THROW_ON_ERROR);
    }
}
