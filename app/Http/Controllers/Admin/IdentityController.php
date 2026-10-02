<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\CompanyIdentity;
use App\Http\Controllers\Controller;
use App\Http\Requests\PortalAccess\IdentityRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Identidad de la empresa (/admin/identidad, SPEC §14, D-067), solo admin: nombre y logo, que se usan
 * en la cabecera del portal, en los emails y en los PDF. Los colores del tema no se cambian aquí
 * (AA verificado, D-011 y D-012). Cada cambio queda en la auditoría (activity_log `settings`).
 */
class IdentityController extends Controller
{
    public function __construct(private readonly CompanyIdentity $identity) {}

    public function edit(Request $request): Response
    {
        $this->ensureAdmin($request);

        return Inertia::render('admin/identity', [
            'identity' => [
                'company_name' => $this->identity->name(),
                'logo' => $this->identity->forPortal()['logo'],
            ],
            'limits' => [
                'max_kb' => CompanyIdentity::MAX_KILOBYTES,
                'max_side' => CompanyIdentity::MAX_SOURCE_SIDE,
            ],
        ]);
    }

    public function update(IdentityRequest $request): RedirectResponse
    {
        $logo = $request->logo();
        $this->identity->update($request->companyName(), $logo);

        $this->log($request, 'identity_updated', ['company_name' => $request->companyName(), 'logo_changed' => $logo !== null]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('portal.identity.saved')]);

        return to_route('admin.identity.edit');
    }

    public function destroyLogo(Request $request): RedirectResponse
    {
        $this->ensureAdmin($request);

        $this->identity->removeLogo();

        $this->log($request, 'identity_logo_removed', []);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('portal.identity.logo_removed')]);

        return to_route('admin.identity.edit');
    }

    private function ensureAdmin(Request $request): void
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->isAdmin(), 403);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function log(Request $request, string $event, array $properties): void
    {
        /** @var User $actor */
        $actor = $request->user();

        activity('settings')
            ->causedBy($actor)
            ->event($event)
            ->withProperties($properties)
            ->log($event);
    }
}
