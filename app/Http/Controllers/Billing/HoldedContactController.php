<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\HoldedContactResolver;
use App\Domain\Billing\ReviewUndo;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\HoldedContact;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Contactos de Holded y su cliente de Audax (Fase 12, D-387): resolver uno es elegir su cliente (sus
 * facturas pasan a ese cliente), dar por bueno el de un nombre parecido, descartarlo (no es un
 * cliente de la agencia) o volver a casarlo solo (HoldedContactResolver). Se hace desde la bandeja
 * «Por revisar» (I5, D-413) y desde el directorio de Ajustes; cada cambio se puede deshacer
 * (ReviewUndo, D-414). Nunca se crea un cliente desde aquí.
 */
class HoldedContactController extends Controller
{
    public function update(Request $request, HoldedContact $contact, HoldedContactResolver $resolver, ReviewUndo $undo): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', 'in:'.implode(',', HoldedContactResolver::ACTIONS)],
            'client_id' => ['required_if:action,assign', 'nullable', 'integer', 'exists:clients,id'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $before = $resolver->apply($contact, (string) $data['action'], isset($data['client_id']) ? (int) $data['client_id'] : null, $user);

        $message = match (true) {
            $contact->client_id !== null => __('billing.contacts.assigned', ['contact' => $contact->name, 'client' => (string) Client::query()->withTrashed()->whereKey($contact->client_id)->value('name')]),
            $contact->ignored_at !== null => __('billing.contacts.ignored', ['contact' => $contact->name]),
            default => __('billing.contacts.reset', ['contact' => $contact->name]),
        };
        $undo->remember($message, [$before]);
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    /**
     * /facturacion/contactos (D-405 y D-413): las vistas de trabajo van a «Por revisar» (contactos);
     * «Todos» y «Descartados», al directorio de Ajustes. Con un 301.
     */
    public function moved(Request $request): RedirectResponse
    {
        $view = $request->query('vista');

        if (in_array($view, ['todos', 'descartados'], true)) {
            return redirect()->to('/facturacion/ajustes?contactos='.$view.'#contactos-holded', 301);
        }

        return redirect()->to('/facturacion/por-revisar?tipo=contactos', 301);
    }
}
