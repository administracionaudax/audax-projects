<?php

namespace App\Http\Controllers;

use App\Http\Requests\Home\UpdateHomeLayoutRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Orden de las tarjetas de Inicio de quien lo mira (D-138). La interfaz lo guarda sin recargar la
 * página (petición JSON al soltar una tarjeta), así que las dos acciones responden 204.
 */
class HomeLayoutController extends Controller
{
    public function update(UpdateHomeLayoutRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['home_layout' => $request->cards()])->save();

        return response()->noContent();
    }

    /**
     * Restablecer: vuelve al orden por defecto.
     */
    public function destroy(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['home_layout' => null])->save();

        return response()->noContent();
    }
}
