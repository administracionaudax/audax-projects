<?php

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Páginas de error de la app (UX-03, SPEC §19: textos en español y errores comprensibles): los
 * 403, 404, 419, 429, 500 y 503 de las peticiones que no esperan JSON se pintan con la página
 * Inertia «error», con el tema de la app y un mensaje propio (nunca el texto en inglés de la
 * excepción, p. ej. el de Spatie «User does not have the right roles.»).
 * - Las peticiones JSON (fetch de la campana, buscadores…) siguen recibiendo JSON.
 * - Con APP_DEBUG, un 500 muestra la página de depuración de Laravel.
 * - Un 419 (sesión o CSRF caducados) en una visita de Inertia vuelve a la página anterior con un
 *   aviso, en lugar de sustituirla y perder el formulario.
 */
final class ErrorPage
{
    public const array STATUSES = [403, 404, 419, 429, 500, 503];

    public static function respond(Response $response, Request $request): Response
    {
        $status = $response->getStatusCode();

        if (! in_array($status, self::STATUSES, true)
            || $request->expectsJson()
            || $request->is('api/*')
            || ($status === 500 && (bool) config('app.debug'))) {
            return $response;
        }

        try {
            if ($status === 419 && $request->hasHeader('X-Inertia') && $request->hasSession()) {
                Inertia::flash('toast', ['type' => 'warning', 'message' => __('app.page_expired')]);

                return redirect()->back(303);
            }

            return Inertia::render('error', ['status' => $status])
                ->toResponse($request)
                ->setStatusCode($status);
        } catch (Throwable) {
            // Si ni siquiera se puede pintar la página (p. ej. sin base de datos), la respuesta
            // original de Laravel.
            return $response;
        }
    }
}
