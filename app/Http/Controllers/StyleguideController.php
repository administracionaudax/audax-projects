<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Guía de estilo (SPEC §3.1). Pública si config('app.styleguide_public'); si no, solo admin.
 */
class StyleguideController extends Controller
{
    public function __invoke(Request $request): Response
    {
        // CONTRATO: implementar la comprobación de acceso (agente backend).
        return Inertia::render('styleguide');
    }
}
