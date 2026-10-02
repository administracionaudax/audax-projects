<?php

namespace App\Http\Controllers\Absences;

use App\Enums\AbsenceType;
use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Inertia\Inertia;

/**
 * Base de los controladores de ausencias: autorización con AbsencePolicy y avisos (toasts).
 */
abstract class AbsencesController extends Controller
{
    use AuthorizesRequests;

    /**
     * @param  'success'|'info'|'warning'|'error'  $type
     */
    protected function toast(string $message, string $type = 'success'): void
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);
    }

    /**
     * Tipos de ausencia en el orden del formulario (los textos los pone el frontend).
     *
     * @return list<string>
     */
    protected function types(): array
    {
        return AbsenceType::values();
    }
}
