<?php

namespace App\Listeners\Chat;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Request;

/**
 * Al cerrar sesión, el navegador en el que se cierra deja de recibir los avisos de esa persona
 * (un ordenador compartido no debe seguir mostrando sus mensajes). Se reconoce por la sesión con
 * la que se activaron. Si la misma persona vuelve a entrar en ese navegador, la app los reactiva
 * sola (resources/js/components/realtime/push.ts); otra persona tendría que activarlos.
 */
final class ForgetPushSubscriptionsOnLogout
{
    public function __construct(private readonly Request $request) {}

    public function handle(Logout $event): void
    {
        if (! $event->user instanceof User || ! $this->request->hasSession()) {
            return;
        }

        PushSubscription::query()
            ->where('user_id', $event->user->id)
            ->where('session_hash', hash('sha256', $this->request->session()->getId()))
            ->delete();
    }
}
