<?php

namespace App\Http\Requests\Projects\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * La persona existe, está activa y es de la agencia (no un usuario del portal de clientes).
 * Gestores y miembros de proyecto siempre son internos (SPEC §5).
 */
class ActiveInternalUser implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $valid = is_numeric($value)
            && User::query()->active()->internal()->whereKey((int) $value)->exists();

        if (! $valid) {
            $fail(__('projects.errors.user_not_available'));
        }
    }
}
