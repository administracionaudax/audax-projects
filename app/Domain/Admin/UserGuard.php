<?php

namespace App\Domain\Admin;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de gestión de usuarios (SPEC §14) que no dependen de un formulario:
 * - solo un admin puede gestionar a otro admin o dar el rol de admin (quien tenga manage-users sin
 *   ser admin no puede ascenderse ni tocar a los admins),
 * - nadie puede quitarse a sí mismo el rol de admin ni desactivarse,
 * - siempre queda al menos un admin activo: no se puede degradar ni desactivar al último.
 */
final class UserGuard
{
    /**
     * @throws AuthorizationException
     */
    public function assertCanManage(User $actor, User $user): void
    {
        if ($user->isClient()) {
            throw new AuthorizationException(__('admin.users.errors.client_user'));
        }

        if ($user->isAdmin() && ! $actor->isAdmin()) {
            throw new AuthorizationException(__('admin.users.errors.admin_only'));
        }
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function assertCanChangeRole(User $actor, User $user, Role $role): void
    {
        $this->assertCanManage($actor, $user);

        if ($role === Role::Admin && ! $actor->isAdmin()) {
            throw ValidationException::withMessages(['role' => __('admin.users.errors.grant_admin')]);
        }

        if (! $user->isAdmin() || $role === Role::Admin) {
            return;
        }

        if ($actor->id === $user->id) {
            throw ValidationException::withMessages(['role' => __('admin.users.errors.self_demote')]);
        }

        if ($user->is_active && $this->isLastActiveAdmin($user)) {
            throw ValidationException::withMessages(['role' => __('admin.users.errors.last_admin')]);
        }
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function assertCanDeactivate(User $actor, User $user): void
    {
        $this->assertCanManage($actor, $user);

        if (! $user->is_active) {
            throw ValidationException::withMessages(['user' => __('admin.users.errors.already_inactive')]);
        }

        if ($actor->id === $user->id) {
            throw ValidationException::withMessages(['user' => __('admin.users.errors.self_deactivate')]);
        }

        if ($user->isAdmin() && $this->isLastActiveAdmin($user)) {
            throw ValidationException::withMessages(['user' => __('admin.users.errors.last_admin')]);
        }
    }

    public function isLastActiveAdmin(User $user): bool
    {
        return ! User::role(Role::Admin->value)
            ->where('is_active', true)
            ->whereKeyNot($user->id)
            ->exists();
    }
}
