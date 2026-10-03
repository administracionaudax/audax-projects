<?php

namespace App\Http\Requests\Projects\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * La persona existe, está activa y es de la agencia (no un usuario del portal de clientes).
 * Gestores y miembros de proyecto siempre son internos (SPEC §5). Con $manager, además, no es un
 * colaborador externo: nunca es gestor de un proyecto (D-134).
 */
class ActiveInternalUser implements ValidationRule
{
    public function __construct(private readonly bool $manager = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $user = is_numeric($value)
            ? User::query()->active()->internal()->whereKey((int) $value)->first()
            : null;

        if ($user === null) {
            $fail(__('projects.errors.user_not_available'));

            return;
        }

        if ($this->manager && $user->isCollaborator()) {
            $fail(__('projects.errors.collaborator_cannot_manage'));
        }
    }
}
