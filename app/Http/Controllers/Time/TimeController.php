<?php

namespace App\Http\Controllers\Time;

use App\Domain\Time\TimeEntryResult;
use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Inertia\Inertia;

/**
 * Base de los controladores de horas: autorización con políticas y avisos (toasts).
 *
 * - `toast`: el aviso principal (props flash de Inertia, lo pinta use-flash-toast).
 * - `time_warnings`: avisos no bloqueantes de la imputación (TimeEntryWarning: tarea completada,
 *   jornada superada, exceso de bolsa). Los pinta la cabecera, uno por aviso.
 */
abstract class TimeController extends Controller
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
     * @param  list<TimeEntryResult>  $results
     */
    protected function flashWarnings(array $results): void
    {
        $warnings = [];

        foreach ($results as $result) {
            foreach ($result->warningsArray() as $warning) {
                $warnings[$warning['message']] = $warning;
            }
        }

        if ($warnings !== []) {
            Inertia::flash('time_warnings', array_values($warnings));
        }
    }
}
