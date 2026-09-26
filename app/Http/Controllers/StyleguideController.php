<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Guía de estilo (SPEC §3.1). Pública si config('app.styleguide_public'); si no, solo admin.
 */
class StyleguideController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if (! config('app.styleguide_public')) {
            $user = $request->user();

            if (! $user instanceof User) {
                return redirect()->guest(route('login'));
            }

            abort_unless($user->isActive() && $user->isAdmin(), 403);
        }

        return Inertia::render('styleguide');
    }
}
