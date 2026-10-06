<?php

namespace App\Http\Middleware;

use App\Domain\Weeklies\AppModules;
use App\Enums\AppModule;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `module:weeklies` (F-177, D-151): si el módulo está apagado en los ajustes, la ruta no existe (404).
 * En modo de prueba (D-239), un admin sí entra: la petición queda marcada (`module_preview`) y la
 * página enseña el aviso «Modo de prueba».
 */
class EnsureModuleEnabled
{
    public const string PREVIEW_ATTRIBUTE = 'module_preview';

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $user = $request->user();
        $user = $user instanceof User ? $user : null;
        $appModule = AppModule::from($module);

        abort_unless(AppModules::visibleTo($user, $appModule), 404);

        if (AppModules::previewing($user, $appModule)) {
            $request->attributes->set(self::PREVIEW_ATTRIBUTE, true);
        }

        return $next($request);
    }
}
