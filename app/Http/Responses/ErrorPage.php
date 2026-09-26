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
 * - Solo en las visitas de página completa: la carga normal del navegador y la navegación de
 *   Inertia (GET sin recarga parcial). Las acciones de Inertia dentro de una página (POST, PATCH,
 *   DELETE… y las recargas parciales) siguen recibiendo la respuesta de Laravel, que sus
 *   onHttpException tratan sin salir de la página (deshacer y avisar); si alguna no la trata,
 *   el modal de Inertia la enseña en español (lang/es.json traduce las vistas de error).
 * - Las peticiones JSON (fetch de la campana, buscadores…) siguen recibiendo JSON.
 * - Con APP_DEBUG, un 500 muestra la página de depuración de Laravel.
 * - Un 419 (sesión o CSRF caducados) en una acción de Inertia vuelve a la página anterior con un
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

        $inertia = $request->hasHeader('X-Inertia');

        try {
            if ($status === 419 && $inertia && $request->hasSession()) {
                Inertia::flash('toast', ['type' => 'warning', 'message' => __('app.page_expired')]);

                return redirect()->back(303);
            }

            if ($inertia && (! $request->isMethod('GET') || $request->hasHeader('X-Inertia-Partial-Data'))) {
                return $response;
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
