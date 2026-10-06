<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Users\AvatarStorage;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * La foto de perfil (F-029, D-234): subirla recortada desde «Perfil», quitarla y servirla con una
 * URL firmada (nunca es pública, SPEC §15).
 */
class AvatarController extends Controller
{
    /** Tamaño máximo de la imagen que se sube (ya recortada en el navegador), en kilobytes. */
    public const int MAX_KB = 5120;

    public function update(Request $request, AvatarStorage $avatars): RedirectResponse
    {
        $request->validate([
            'avatar' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:'.self::MAX_KB],
        ], [
            'avatar.required' => __('app.avatar.invalid'),
            'avatar.image' => __('app.avatar.invalid'),
            'avatar.mimes' => __('app.avatar.invalid'),
            'avatar.max' => __('app.avatar.too_large', ['mb' => intdiv(self::MAX_KB, 1024)]),
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $avatars->store($user, $request->file('avatar'));
        } catch (RuntimeException) {
            throw ValidationException::withMessages(['avatar' => __('app.avatar.invalid')]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('app.avatar.updated')]);

        return back();
    }

    public function destroy(Request $request, AvatarStorage $avatars): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $avatars->remove($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('app.avatar.removed')]);

        return back();
    }

    /** La foto de una persona, con la URL firmada de User::avatar_url. */
    public function show(User $user): StreamedResponse
    {
        abort_if($user->avatar_path === null || ! Storage::disk(AvatarStorage::DISK)->exists($user->avatar_path), 404);

        return Storage::disk(AvatarStorage::DISK)->response($user->avatar_path, null, [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
