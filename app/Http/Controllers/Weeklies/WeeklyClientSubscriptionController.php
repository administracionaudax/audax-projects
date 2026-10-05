<?php

namespace App\Http\Controllers\Weeklies;

use App\Domain\Weeklies\WeeklyClientSubscriptions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Weeklies\JoinClientsRequest;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * «Unirme a clientes» y «Dejar cliente» desde la Weekly (F-034 y F-133, D-221): cualquier interno de
 * plantilla se une al equipo de la Weekly de clientes activos y los deja cuando quiere. Es una
 * suscripción de la Weekly (WeeklyClientSubscriptions): NO le hace miembro de ningún proyecto, así
 * que no gana chat, horas, tareas ni bolsas. Los colaboradores externos no (D-134).
 */
class WeeklyClientSubscriptionController extends Controller
{
    public function join(JoinClientsRequest $request, WeeklyClientSubscriptions $subscriptions): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $ids = $request->clientIds();

        $found = Client::query()->whereKey($ids)->where('is_active', true)->count();

        if ($found !== count($ids)) {
            throw ValidationException::withMessages(['client_ids' => __('weeklies.validation.clients')]);
        }

        $joined = $subscriptions->subscribe($user, $ids);

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('weeklies.flash.joined', $joined, ['count' => $joined])]);

        return back();
    }

    public function leave(Request $request, Client $client, WeeklyClientSubscriptions $subscriptions): RedirectResponse
    {
        Gate::authorize('use-weeklies');

        /** @var User $user */
        $user = $request->user();

        if (! $subscriptions->unsubscribe($user, $client)) {
            abort(404);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('weeklies.flash.left', ['client' => $client->name])]);

        return back();
    }
}
