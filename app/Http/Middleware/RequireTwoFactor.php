<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Si el ajuste require_2fa está activo, quien no tenga 2FA confirmado solo puede ir a sus ajustes
 * de seguridad para activarlo (SPEC §14).
 */
class RequireTwoFactor
{
    /**
     * Rutas que siguen disponibles sin 2FA para poder activarlo o salir.
     *
     * @var list<string>
     */
    private const array EXCEPT = [
        'security.edit',
        'user-password.update',
        'password.confirm',
        'password.confirmation',
        'password.confirm.store',
        'two-factor.*',
        'logout',
        'profile.*',
        'appearance.*',
        'sessions.*',
    ];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User
            || $user->two_factor_confirmed_at !== null
            || $request->routeIs(...self::EXCEPT)
            || ! (bool) Setting::get('require_2fa', false)) {
            return $next($request);
        }

        $message = __('app.two_factor_required');

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], Response::HTTP_FORBIDDEN);
        }

        Inertia::flash('toast', ['type' => 'warning', 'message' => $message]);

        return redirect()->route('security.edit')->with('status', $message);
    }
}
