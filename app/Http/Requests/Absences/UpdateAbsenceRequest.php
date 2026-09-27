<?php

namespace App\Http\Requests\Absences;

use App\Models\Absence;
use Illuminate\Support\Facades\Gate;

/**
 * Modificar una ausencia aprobada de otra persona (D-049): los mismos campos que al solicitarla. Solo
 * quien puede aprobarla (AbsencePolicy::update); las reglas del negocio las aplica AbsenceService.
 */
class UpdateAbsenceRequest extends StoreAbsenceRequest
{
    public function authorize(): bool
    {
        $absence = $this->route('absence');

        return $absence instanceof Absence && Gate::allows('update', $absence);
    }
}
