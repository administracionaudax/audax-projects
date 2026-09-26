<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

/**
 * Clientes (D-021, D-022): los ven todos los internos; los crean y editan admins y responsables.
 * No se borran: se desactivan (D-037).
 */
class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isInternal();
    }

    public function view(User $user, Client $client): bool
    {
        return $user->isInternal();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isDepartmentManager();
    }

    public function update(User $user, Client $client): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, Client $client): bool
    {
        return false;
    }
}
