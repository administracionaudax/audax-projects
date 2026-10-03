<?php

namespace App\Http\Middleware;

use App\Domain\Access\CollaboratorAccess;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Colaboradores externos (D-134): las rutas internas están cerradas por defecto. Un colaborador
 * solo entra en las de config/collaborators.php; en el resto recibe 403 (JSON si lo pide). Va antes
 * de buscar los modelos de la URL (bootstrap/app.php), así que no distingue un id que existe de
 * uno que no. Para el resto de usuarios no hace nada.
 */
class RestrictCollaborators
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $route = $request->route();

        if ($user instanceof User && $user->isCollaborator() && ! CollaboratorAccess::allows($route instanceof Route ? $route : null)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => __('app.collaborator_forbidden')], Response::HTTP_FORBIDDEN);
            }

            abort(Response::HTTP_FORBIDDEN, __('app.collaborator_forbidden'));
        }

        return $next($request);
    }
}
