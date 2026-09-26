<?php

namespace App\Http\Middleware;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Props compartidas con todas las páginas (contrato con resources/js/types).
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar_url,
                    'theme_preference' => $user->theme_preference,
                    'two_factor_enabled' => ! is_null($user->two_factor_confirmed_at),
                    'roles' => $user->getRoleNames()->values()->all(),
                    'is_client' => $user->isClient(),
                ] : null,
                'can' => [
                    'viewHourBanks' => $user ? Gate::forUser($user)->allows('view-hour-banks') : false,
                    'viewAdmin' => $user?->hasRole('admin') ?? false,
                    'viewFinancials' => $user ? Gate::forUser($user)->allows('view-financials') : false,
                ],
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
