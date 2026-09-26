<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Base de los Resources con datos económicos: solo se incluyen si quien pide puede verlos
 * (view-financials, SPEC §5). Nunca se envían ocultos al navegador.
 *
 * La gate se evalúa UNA vez por petición y persona (se guarda en los atributos de la petición):
 * los listados serializan cientos de filas y cada evaluación pasa por Gate::before y por
 * checkPermissionTo de spatie (PERF-02).
 */
abstract class FinancialResource extends JsonResource
{
    protected function canSeeFinancials(Request $request): bool
    {
        return self::financialsVisibleTo($request);
    }

    /**
     * ¿Puede quien hace la petición ver datos económicos? Memorizado en la petición.
     */
    public static function financialsVisibleTo(Request $request): bool
    {
        $user = $request->user();

        if ($user === null) {
            return false;
        }

        $key = 'audax.view-financials.'.$user->getAuthIdentifier();

        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, Gate::forUser($user)->allows('view-financials'));
        }

        return (bool) $request->attributes->get($key);
    }
}
