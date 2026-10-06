<?php

namespace App\Http\Controllers;

use App\Http\Requests\Navigation\UpdateNavSectionsRequest;
use App\Models\User;
use Illuminate\Http\Response;

/**
 * Secciones plegadas de la barra lateral de quien la mira (D-260). La interfaz lo guarda sin
 * recargar la página (petición JSON al plegar o desplegar), así que responde 204. Sin ninguna
 * plegada se guarda null (el valor por defecto).
 */
class NavSectionsController extends Controller
{
    public function update(UpdateNavSectionsRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $collapsed = $request->collapsed();
        $user->forceFill(['nav_collapsed' => $collapsed === [] ? null : $collapsed])->save();

        return response()->noContent();
    }
}
