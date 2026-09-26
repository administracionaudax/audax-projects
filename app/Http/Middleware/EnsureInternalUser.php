<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Solo usuarios internos. Un cliente nunca ve la aplicación interna: se le envía a su portal (SPEC §5).
 */
class EnsureInternalUser
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isClient()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => __('app.forbidden')], Response::HTTP_FORBIDDEN);
            }

            return redirect()->route('portal.home');
        }

        return $next($request);
    }
}
