<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Páginas que cambiaron de URL: las que pasaron de /informes a /facturacion (D-401) y las de la nueva
 * navegación de Facturación (D-405: Ventas, Por facturar y Por revisar). Un 301 a la URL nueva (el
 * `to` de la ruta) con la misma query, para que sigan valiendo los favoritos, los enlaces de los
 * correos y las descargas con ?formato=. No comprueba permisos: los comprueba la URL nueva.
 */
class MovedReportController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $to = $request->route()?->defaults['to'] ?? null;
        $to = is_string($to) ? $to : '/facturacion';
        $query = (string) $request->getQueryString();

        return redirect()->to($query === '' ? $to : $to.'?'.$query, 301);
    }
}
