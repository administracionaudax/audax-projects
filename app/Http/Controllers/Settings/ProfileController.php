<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Perfil propio: nombre, correo y puesto (F-026). Los datos económicos (coste/hora, tarifa) los gestiona el
 * admin y nunca se exponen aquí. No hay borrado de cuenta propio: los usuarios se desactivan (SPEC §14).
 */
class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            // Puesto (Fase 10, F-026): no va en las props compartidas.
            'jobTitle' => $request->user()?->job_title,
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->fill($request->safe()->only(['name', 'email']));

        if ($request->exists('job_title')) {
            $title = trim((string) $request->input('job_title'));
            $user->job_title = $title === '' ? null : $title;
        }

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('app.profile_updated')]);

        return to_route('profile.edit');
    }
}
