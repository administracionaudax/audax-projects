<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Notifications\NotificationPreferences;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\NotificationSettingsRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /ajustes/notificaciones (SPEC §13, D-073): qué avisos recibe cada persona y por qué canal (en la
 * app, email y avisos del navegador) y el resumen diario por email. Solo internos (la ruta está
 * en el grupo internal): a los clientes solo les llegan los emails de su portal, que no forman
 * parte de las preferencias.
 *
 * Todo lo decide NotificationPreferences: forUser pinta la matriz (solo los eventos que se le
 * ofrecen a la persona) y update guarda solo lo que difiere del catálogo, sin tocar los
 * obligatorios ni lo que no se ofrece.
 */
class NotificationSettingsController extends Controller
{
    public function edit(Request $request, NotificationPreferences $preferences): Response
    {
        return Inertia::render('settings/notifications', [
            'settings' => $preferences->forUser($this->user($request)),
        ]);
    }

    public function update(NotificationSettingsRequest $request, NotificationPreferences $preferences): RedirectResponse
    {
        $preferences->update($this->user($request), $request->events(), $request->dailyDigest());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('notifications.settings.saved')]);

        return to_route('notification-settings.edit');
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
