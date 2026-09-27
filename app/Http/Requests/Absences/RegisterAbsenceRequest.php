<?php

namespace App\Http\Requests\Absences;

use App\Models\Absence;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;

/**
 * Registrar una ausencia ya aprobada de alguien del equipo (D-049): lo mismo que solicitarla más la
 * persona. Que la persona sea de su ámbito lo comprueba AbsenceService (AbsencePolicy::register).
 */
class RegisterAbsenceRequest extends StoreAbsenceRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewTeam', Absence::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }

    public function target(): User
    {
        return User::query()->findOrFail($this->integer('user_id'));
    }
}
