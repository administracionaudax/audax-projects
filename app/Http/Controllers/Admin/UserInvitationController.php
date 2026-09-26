<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\UserGuard;
use App\Domain\Admin\UserInviter;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * «Reenviar invitación»: un enlace nuevo para fijar la contraseña (invalida los anteriores).
 * Solo para personas activas; la ruta lleva límite de frecuencia.
 */
class UserInvitationController extends Controller
{
    public function store(Request $request, User $user, UserInviter $inviter, UserGuard $guard): RedirectResponse
    {
        Gate::authorize('manage-users');

        /** @var User $actor */
        $actor = $request->user();
        $guard->assertCanManage($actor, $user);

        if (! $user->is_active) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('admin.users.errors.invite_inactive')]);

            return back();
        }

        $inviter->send($actor, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.users.invitation_resent', ['email' => $user->email])]);

        return back();
    }
}
