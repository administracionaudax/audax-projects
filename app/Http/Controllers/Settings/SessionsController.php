<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sesiones activas del usuario (SPEC §15). Driver de sesión: database.
 * Props de la página settings/sessions:
 *   sessions: [{id, ip_address, user_agent, browser, platform, is_current, last_active_at (ISO)}]
 */
class SessionsController extends Controller
{
    public function index(Request $request): Response
    {
        // CONTRATO: implementar (agente backend).
        return Inertia::render('settings/sessions', ['sessions' => []]);
    }

    public function destroy(Request $request, string $session): RedirectResponse
    {
        // CONTRATO: cerrar una sesión propia que no sea la actual (agente backend).
        return back();
    }

    public function destroyOthers(Request $request): RedirectResponse
    {
        // CONTRATO: cerrar todas las demás sesiones propias (agente backend).
        return back();
    }
}
