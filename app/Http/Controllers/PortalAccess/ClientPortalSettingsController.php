<?php

namespace App\Http\Controllers\PortalAccess;

use App\Http\Controllers\Controller;
use App\Http\Requests\PortalAccess\ClientPortalSettingsRequest;
use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Ajustes del portal de un cliente (D-064, D-065): cómo se nombra a las personas, qué horas ve y
 * los avisos de bolsa por email. Quien edita el cliente (ClientPolicy::update). El cambio queda en la
 * auditoría del cliente (LogsDomainActivity).
 */
class ClientPortalSettingsController extends Controller
{
    public function update(ClientPortalSettingsRequest $request, Client $client): RedirectResponse
    {
        $client->fill($request->settings())->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('portal.access.settings_saved')]);

        return back();
    }
}
