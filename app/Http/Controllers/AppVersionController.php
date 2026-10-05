<?php

namespace App\Http\Controllers;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Versión de la interfaz (F-013): la misma que Inertia manda en cada respuesta. La pestaña abierta
 * la consulta de vez en cuando y, si ha cambiado (despliegue nuevo), recarga sola cuando no hay nada
 * a medias o avisa para recargar.
 */
class AppVersionController extends Controller
{
    public function __invoke(Request $request, HandleInertiaRequests $inertia): JsonResponse
    {
        return response()->json(['version' => $inertia->version($request)])
            ->header('Cache-Control', 'no-store');
    }
}
