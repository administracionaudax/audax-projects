<?php

namespace App\Http\Resources\Projects;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Convierte un Resource en un array plano, resolviendo también los Resources anidados
 * (ProjectResource → owner, HourBankResource → department…).
 *
 * JsonResource::resolve() deja los anidados como objetos, e Inertia los resuelve con
 * toResponse(), que los envuelve en {"data": …}: el `owner` llegaría como {data: {…}} y no como
 * el UserSummary del contrato (resources/js/types/domain.ts). Aquí se resuelven sin envolver.
 */
final class ResourceData
{
    /**
     * @return array<array-key, mixed>
     */
    public static function of(JsonResource $resource, Request $request): array
    {
        /** @var array<array-key, mixed> */
        return self::deep($resource->resolve($request), $request);
    }

    private static function deep(mixed $value, Request $request): mixed
    {
        if ($value instanceof JsonResource) {
            return self::deep($value->resolve($request), $request);
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::deep($item, $request);
            }
        }

        return $value;
    }
}
