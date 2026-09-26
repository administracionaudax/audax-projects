<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Listeners\RecordSuccessfulLogin;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Preferencia de tema (claro/oscuro/sistema) guardada en users.theme_preference y en la cookie "appearance".
 */
class AppearanceController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('settings/appearance');
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'theme' => ['required', 'string', Rule::in(User::THEMES)],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['theme_preference' => $validated['theme']])->save();

        return back()->withCookie(cookie(
            'appearance',
            $validated['theme'],
            RecordSuccessfulLogin::APPEARANCE_COOKIE_MINUTES,
        ));
    }
}
