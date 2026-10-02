<?php

namespace App\Http\Controllers\PortalAccess;

use App\Domain\Portal\Access\PortalUsers;
use App\Http\Controllers\Controller;
use App\Http\Requests\PortalAccess\InvitePortalUserRequest;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Usuarios del portal desde la ficha del cliente (SPEC §11, D-063): invitar, reenviar la invitación,
 * revocar el acceso (desactiva y cierra sus sesiones al momento) y reactivarlo. Nunca se borra a
 * nadie. Quién: ClientPolicy::managePortal (admin, responsables y gestores de algún proyecto del
 * cliente). La persona tiene que ser del portal de ESE cliente (scopeBindings + rol cliente): si no, 404.
 */
class PortalUserController extends Controller
{
    public function __construct(private readonly PortalUsers $users) {}

    public function store(InvitePortalUserRequest $request, Client $client): RedirectResponse
    {
        $user = $this->users->invite(
            $this->actor($request),
            $client,
            $request->string('name')->toString(),
            $request->string('email')->toString(),
        );

        return $this->done(__('portal.access.invited', ['email' => $user->email]));
    }

    public function resend(Request $request, Client $client, User $portalUser): RedirectResponse
    {
        $this->authorizeFor($client, $portalUser);
        $this->users->resend($this->actor($request), $client, $portalUser);

        return $this->done(__('portal.access.invitation_resent', ['email' => $portalUser->email]));
    }

    public function revoke(Request $request, Client $client, User $portalUser): RedirectResponse
    {
        $this->authorizeFor($client, $portalUser);
        $this->users->revoke($this->actor($request), $client, $portalUser);

        return $this->done(__('portal.access.revoked', ['name' => $portalUser->name]));
    }

    public function reactivate(Request $request, Client $client, User $portalUser): RedirectResponse
    {
        $this->authorizeFor($client, $portalUser);
        $this->users->reactivate($this->actor($request), $client, $portalUser);

        return $this->done(__('portal.access.reactivated', ['name' => $portalUser->name]));
    }

    private function authorizeFor(Client $client, User $portalUser): void
    {
        Gate::authorize('managePortal', $client);

        abort_unless($portalUser->client_id === $client->id && $portalUser->isClient(), 404);
    }

    private function actor(Request $request): User
    {
        /** @var User $actor */
        $actor = $request->user();

        return $actor;
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
