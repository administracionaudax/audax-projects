<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Preferencia de tema (claro/oscuro/sistema) guardada en users.theme_preference y en la cookie "appearance".
 */
class AppearanceController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('settings/appearance');
    }

    public function update(Request $request): RedirectResponse
    {
        // CONTRATO: validar theme ∈ {light,dark,system}, guardar en el usuario y en la cookie (agente backend).
        return back();
    }
}
