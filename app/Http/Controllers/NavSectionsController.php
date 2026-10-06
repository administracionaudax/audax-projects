<?php

namespace App\Http\Controllers;

use App\Http\Requests\Navigation\UpdateNavSectionsRequest;
use App\Models\User;
use Illuminate\Http\Response;

/**
 * Secciones plegadas de la barra lateral de quien la mira (D-260). La interfaz lo guarda sin
 * recargar la página (petición JSON al plegar o desplegar), así que responde 204. Se guarda tal
 * cual, también la lista vacía (todo desplegado): null es «aún no ha tocado nada» (D-261).
 */
class NavSectionsController extends Controller
{
    public function update(UpdateNavSectionsRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $collapsed = $request->collapsed();
        $user->forceFill(['nav_collapsed' => $collapsed])->save();

        return response()->noContent();
    }
}
