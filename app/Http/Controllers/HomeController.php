<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inicio: panel personal del usuario (SPEC §5.1). En la Fase 0 muestra la estructura con estados vacíos.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('home');
    }
}
