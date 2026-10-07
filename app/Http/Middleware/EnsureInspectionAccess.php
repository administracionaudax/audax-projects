<?php

namespace App\Http\Middleware;

use App\Domain\People\InspectionAccesses;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Las páginas de solo lectura de la Inspección (`/inspeccion…`, D-353): solo con un acceso de la
 * sesión que siga valiendo (encendido, en plazo, sin revocar ni bloquear). Si no, 404: no se
 * distingue un acceso caducado de uno que no existe.
 */
class EnsureInspectionAccess
{
    public function __construct(private readonly InspectionAccesses $accesses) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $access = $this->accesses->current($request);
        abort_if($access === null, 404);

        $request->attributes->set('inspection_access', $access);

        return $next($request);
    }
}
