<?php

namespace App\Broadcasting;

use App\Models\PushSubscription;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Envía un aviso Web Push a los navegadores de una persona (D-072) con minishlink/web-push (MIT):
 * cifrado aes128gcm y firma VAPID. Se llama desde la cola (WebPushChannel), nunca en la petición.
 *
 * - 404 o 410: el navegador ya no tiene esa suscripción (caducada o dada de baja) → se borra.
 * - Otros fallos: se registran y, tras MAX_FAILURES seguidos, la suscripción se borra (una clave
 *   VAPID cambiada, por ejemplo, la dejaría fallando para siempre).
 * - Cada suscripción va por separado: una rota no impide avisar en los demás navegadores.
 */
final class WebPushSender
{
    public const int MAX_FAILURES = 5;

    public function __construct(private readonly PushHttpClient $client) {}

    /**
     * @param  Collection<int, PushSubscription>  $subscriptions
     * @return array{sent: int, expired: int, failed: int}
     */
    public function send(Collection $subscriptions, WebPushMessage $message): array
    {
        $result = ['sent' => 0, 'expired' => 0, 'failed' => 0];
        $vapid = WebPushConfig::vapid();

        if ($vapid === null || $subscriptions->isEmpty()) {
            return $result;
        }

        $factory = new HttpFactory;
        $webPush = new WebPush(['VAPID' => $vapid], [], $this->client, $factory, $factory, null, Log::getLogger());
        $options = array_filter([
            'TTL' => (int) config('services.webpush.ttl', 43200),
            'urgency' => 'high',
            'topic' => $message->topic(),
        ], fn (mixed $value): bool => $value !== null);

        foreach ($subscriptions as $subscription) {
            if (! WebPushConfig::allowsEndpoint($subscription->endpoint)) {
                $subscription->delete();
                $result['expired']++;

                continue;
            }

            try {
                $report = $webPush->sendOneNotification(
                    new Subscription($subscription->endpoint, $subscription->public_key, $subscription->auth_token, $subscription->content_encoding),
                    $message->payload(),
                    $options,
                );
            } catch (Throwable $exception) {
                $this->failed($subscription, $exception->getMessage(), null);
                $result['failed']++;

                continue;
            }

            if ($report->isSuccess()) {
                $subscription->forceFill(['failures' => 0, 'last_used_at' => now()])->save();
                $result['sent']++;
            } elseif ($report->isSubscriptionExpired()) {
                $subscription->delete();
                $result['expired']++;
            } else {
                $this->failed($subscription, $report->getReason(), $report->getResponse()?->getStatusCode());
                $result['failed']++;
            }
        }

        return $result;
    }

    private function failed(PushSubscription $subscription, string $reason, ?int $status): void
    {
        Log::warning('Web Push: el servicio de push rechazó el aviso', [
            'subscription_id' => $subscription->id,
            'status' => $status,
            'reason' => mb_substr($reason, 0, 300),
        ]);

        if ($subscription->failures + 1 >= self::MAX_FAILURES) {
            $subscription->delete();

            return;
        }

        $subscription->forceFill(['failures' => $subscription->failures + 1])->save();
    }
}
