<?php

namespace App\Http\Controllers\Realtime;

use App\Broadcasting\WebPushConfig;
use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Avisos en este navegador (Web Push, D-072):
 * - GET /avisos-navegador: si está activo en el servidor, la clave pública VAPID y los navegadores
 *   que ya tienes suscritos (solo el hash de su endpoint: el navegador compara el suyo),
 * - POST /avisos-navegador/suscripciones: guarda (o reasigna a quien ha iniciado sesión) la
 *   suscripción del navegador; solo servicios de push conocidos y claves bien formadas,
 * - DELETE /avisos-navegador/suscripciones: la quita (solo las propias).
 */
class PushSubscriptionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'enabled' => WebPushConfig::enabled(),
            'public_key' => WebPushConfig::publicKey(),
            'subscriptions' => $this->user($request)->pushSubscriptions()->orderBy('id')->pluck('endpoint_hash')->values()->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! WebPushConfig::enabled()) {
            throw ValidationException::withMessages(['endpoint' => __('realtime.push.disabled')]);
        }

        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048', 'url:https'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', 'string', Rule::in(['aes128gcm', 'aesgcm'])],
        ]);

        $endpoint = (string) $validated['endpoint'];
        $publicKey = (string) $validated['keys']['p256dh'];
        $authToken = (string) $validated['keys']['auth'];

        if (! WebPushConfig::allowsEndpoint($endpoint)) {
            throw ValidationException::withMessages(['endpoint' => __('realtime.push.endpoint')]);
        }
        if (! WebPushConfig::validSubscriptionKeys($publicKey, $authToken)) {
            throw ValidationException::withMessages(['keys' => __('realtime.push.keys')]);
        }

        $user = $this->user($request);
        $subscription = PushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashEndpoint($endpoint)],
            [
                'user_id' => $user->id,
                'endpoint' => $endpoint,
                'public_key' => $publicKey,
                'auth_token' => $authToken,
                'content_encoding' => (string) ($validated['content_encoding'] ?? 'aes128gcm'),
                'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
                'session_hash' => $request->hasSession() ? hash('sha256', $request->session()->getId()) : null,
                'failures' => 0,
            ],
        );

        // Como mucho max_per_user navegadores por persona: se quitan los que llevan más tiempo sin renovarse.
        $keep = max(1, (int) config('services.webpush.max_per_user', 10));
        $stale = $user->pushSubscriptions()->orderByDesc('updated_at')->orderByDesc('id')->skip($keep)->take(100)->pluck('id');
        if ($stale->isNotEmpty()) {
            PushSubscription::query()->whereKey($stale->all())->delete();
        }

        return response()->json(['subscribed' => true], $subscription->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request): Response
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048'],
        ]);

        $this->user($request)->pushSubscriptions()
            ->where('endpoint_hash', PushSubscription::hashEndpoint((string) $validated['endpoint']))
            ->delete();

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
