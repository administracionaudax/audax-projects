<?php

namespace App\Http\Middleware;

use App\Domain\Weeklies\AppModules;
use App\Enums\AppModule;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `module:weeklies` (F-177, D-151): si el módulo está apagado en los ajustes, la ruta no existe (404).
 */
class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless(AppModules::enabled(AppModule::from($module)), 404);

        return $next($request);
    }
}
