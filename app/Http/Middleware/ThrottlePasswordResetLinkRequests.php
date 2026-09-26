<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limita las peticiones de enlace de restablecimiento (POST /forgot-password, ruta password.email de
 * Fortify) por IP y por correo, con el limitador "password-email" (FortifyServiceProvider).
 *
 * Fortify no permite configurar un limitador para esa ruta, así que este middleware va en
 * config('fortify.middleware') y solo actúa sobre ella.
 */
class ThrottlePasswordResetLinkRequests
{
    public const string LIMITER = 'password-email';

    public function __construct(private readonly ThrottleRequests $throttle) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('post') || ! $request->routeIs('password.email')) {
            return $next($request);
        }

        return $this->throttle->handle($request, $next, self::LIMITER);
    }
}
