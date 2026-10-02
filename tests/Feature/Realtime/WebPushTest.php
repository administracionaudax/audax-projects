<?php

use App\Broadcasting\WebPushChannel;
use App\Broadcasting\WebPushConfig;
use App\Broadcasting\WebPushMessage;
use App\Broadcasting\WebPushSender;
use App\Domain\Chat\ConversationDirectory;
use App\Domain\Chat\MessageWriter;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\Chat\ChatDirectMessageNotification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Minishlink\WebPush\VAPID;
use Tests\Feature\Realtime\Support\BrowserPushKeys;

/*
| Web Push (D-072): suscripción desde el navegador, claves VAPID desde .env, envío por la cola
| con el cliente HTTP simulado (contenido cifrado real, que el test descifra), borrado de las
| suscripciones caducadas y canal apagado sin errores si no hay VAPID.
*/

beforeEach(function () {
    Storage::fake('local');
    Http::preventStrayRequests();
    $keys = VAPID::createVapidKeys();
    $this->vapid = $keys;
    config([
        'services.webpush.public_key' => $keys['publicKey'],
        'services.webpush.private_key' => $keys['privateKey'],
        'services.webpush.subject' => 'mailto:no-responder@audaxstudio.com',
    ]);

    $this->ana = User::factory()->employee()->create(['name' => 'Ana']);
    $this->luis = User::factory()->employee()->create(['name' => 'Luis']);
    $this->browser = BrowserPushKeys::generate();
    $this->endpoint = 'https://fcm.googleapis.com/fcm/send/abc123';
    $this->payload = fn (array $overrides = []): array => array_replace_recursive([
        'endpoint' => $this->endpoint,
        'keys' => ['p256dh' => $this->browser->publicKey(), 'auth' => $this->browser->authToken()],
        'content_encoding' => 'aes128gcm',
    ], $overrides);
    $this->subscription = fn (User $user, ?string $endpoint = null): PushSubscription => PushSubscription::query()->create([
        'user_id' => $user->id,
        'endpoint' => $endpoint ?? $this->endpoint,
        'endpoint_hash' => PushSubscription::hashEndpoint($endpoint ?? $this->endpoint),
        'public_key' => $this->browser->publicKey(),
        'auth_token' => $this->browser->authToken(),
    ]);
});

it('sin claves VAPID válidas el canal está apagado y no falla', function (?string $public, ?string $private) {
    config(['services.webpush.public_key' => $public, 'services.webpush.private_key' => $private]);
    ($this->subscription)($this->luis);

    expect(WebPushConfig::enabled())->toBeFalse();
    $this->actingAs($this->luis)->getJson('/avisos-navegador')
        ->assertOk()
        ->assertJson(['enabled' => false, 'public_key' => null]);
    $this->actingAs($this->luis)->postJson('/avisos-navegador/suscripciones', ($this->payload)())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('endpoint');

    // Un aviso con push pedido no llega a llamar a nadie.
    $this->luis->notify(new ChatDirectMessageNotification(1, 1, null, 'Ana', 'Hola', push: true));
    Http::assertNothingSent();
})->with([
    'sin claves' => [null, null],
    'valores de ejemplo de .env.example' => ['cambiar-esta-clave-publica-vapid', 'cambiar-esta-clave-privada-vapid'],
    'solo la pública' => [VAPID::createVapidKeys()['publicKey'], null],
]);

it('da la clave pública y los navegadores ya suscritos (solo el hash del endpoint)', function () {
    ($this->subscription)($this->luis);
    ($this->subscription)($this->ana, 'https://updates.push.services.mozilla.com/wpush/v2/otro');

    $this->actingAs($this->luis)->getJson('/avisos-navegador')
        ->assertOk()
        ->assertExactJson([
            'enabled' => true,
            'public_key' => $this->vapid['publicKey'],
            'subscriptions' => [PushSubscription::hashEndpoint($this->endpoint)],
        ]);
});

it('guarda la suscripción del navegador, la reasigna si cambia la persona y limita cuántas hay', function () {
    $this->actingAs($this->luis)->postJson('/avisos-navegador/suscripciones', ($this->payload)())->assertCreated();
    $this->actingAs($this->luis)->postJson('/avisos-navegador/suscripciones', ($this->payload)())->assertOk();

    $subscription = PushSubscription::query()->sole();
    expect($subscription->user_id)->toBe($this->luis->id)
        ->and($subscription->endpoint)->toBe($this->endpoint)
        ->and($subscription->content_encoding)->toBe('aes128gcm')
        ->and($subscription->session_hash)->not->toBeNull()
        ->and($subscription->toArray())->not->toHaveKeys(['endpoint', 'public_key', 'auth_token']);

    // Mismo navegador, otra persona: la suscripción pasa a quien ha iniciado sesión.
    $this->actingAs($this->ana)->postJson('/avisos-navegador/suscripciones', ($this->payload)())->assertOk();
    expect(PushSubscription::query()->sole()->user_id)->toBe($this->ana->id);

    config(['services.webpush.max_per_user' => 2]);
    foreach (['uno', 'dos', 'tres'] as $i => $name) {
        $this->travel(1)->minutes();
        $this->actingAs($this->ana)->postJson('/avisos-navegador/suscripciones', ($this->payload)(['endpoint' => "https://web.push.apple.com/{$name}"]))->assertCreated();
    }
    expect($this->ana->pushSubscriptions()->pluck('endpoint')->sort()->values()->all())
        ->toBe(['https://web.push.apple.com/dos', 'https://web.push.apple.com/tres']);
});

it('solo admite servicios de push de navegador por https y claves bien formadas (SSRF)', function (array $override, string $error) {
    $this->actingAs($this->luis)->postJson('/avisos-navegador/suscripciones', ($this->payload)($override))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($error);

    expect(PushSubscription::query()->count())->toBe(0);
})->with([
    'otro dominio' => [['endpoint' => 'https://evil.example.com/push'], 'endpoint'],
    'IP interna' => [['endpoint' => 'https://127.0.0.1/push'], 'endpoint'],
    'dominio parecido' => [['endpoint' => 'https://fcm.googleapis.com.evil.example/push'], 'endpoint'],
    'sin https' => [['endpoint' => 'http://fcm.googleapis.com/fcm/send/x'], 'endpoint'],
    'otro puerto' => [['endpoint' => 'https://fcm.googleapis.com:8443/fcm/send/x'], 'endpoint'],
    'p256dh inválida' => [['keys' => ['p256dh' => 'no-es-una-clave']], 'keys'],
    'auth inválido' => [['keys' => ['auth' => 'corto']], 'keys'],
    'codificación desconocida' => [['content_encoding' => 'gzip'], 'content_encoding'],
]);

it('dar de baja quita solo la suscripción propia', function () {
    ($this->subscription)($this->luis);
    ($this->subscription)($this->ana, 'https://web.push.apple.com/ana');

    $this->actingAs($this->ana)->deleteJson('/avisos-navegador/suscripciones', ['endpoint' => $this->endpoint])->assertNoContent();
    expect(PushSubscription::query()->count())->toBe(2);

    $this->actingAs($this->luis)->deleteJson('/avisos-navegador/suscripciones', ['endpoint' => $this->endpoint])->assertNoContent();
    expect($this->luis->pushSubscriptions()->count())->toBe(0)
        ->and($this->ana->pushSubscriptions()->count())->toBe(1);
});

it('envía un aviso cifrado con VAPID que el navegador puede leer', function () {
    ($this->subscription)($this->luis);
    Http::fake(['fcm.googleapis.com/*' => Http::response('', 201)]);

    $dm = app(ConversationDirectory::class)->direct($this->ana, $this->luis);
    $message = app(MessageWriter::class)->post($this->ana, $dm, 'Hola <@'.$this->luis->id.'>, ¿vienes?');

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) use ($dm, $message): bool {
        [$jwt, $key] = sscanf($request->header('Authorization')[0], 'vapid t=%[^,], k=%s');
        $claims = json_decode(base64_decode(strtr(explode('.', (string) $jwt)[1], '-_', '+/')), true);
        $payload = json_decode($this->browser->decrypt($request->body()), true);

        return $request->url() === $this->endpoint
            && $request->method() === 'POST'
            && $request->header('Content-Encoding')[0] === 'aes128gcm'
            && $request->header('TTL')[0] === '43200'
            && $request->header('Urgency')[0] === 'high'
            && $request->header('Topic')[0] === "conversation-{$dm->id}"
            && $key === $this->vapid['publicKey']
            && $claims['aud'] === 'https://fcm.googleapis.com'
            && $claims['sub'] === 'mailto:no-responder@audaxstudio.com'
            && $claims['exp'] > now()->getTimestamp()
            && $payload === [
                'title' => 'Ana te ha escrito',
                'body' => 'Hola @Luis, ¿vienes?',
                'url' => "/tiempo-real/conversaciones/{$dm->id}/abrir?mensaje={$message->id}",
                'tag' => "conversation-{$dm->id}",
            ];
    });

    $subscription = PushSubscription::query()->sole();
    expect($subscription->last_used_at)->not->toBeNull()->and($subscription->failures)->toBe(0);
});

it('borra las suscripciones caducadas (404 y 410) y sigue con las demás', function () {
    ($this->subscription)($this->luis, 'https://fcm.googleapis.com/fcm/send/caducada');
    ($this->subscription)($this->luis, 'https://updates.push.services.mozilla.com/wpush/v2/borrada');
    ($this->subscription)($this->luis, 'https://web.push.apple.com/viva');
    Http::fake([
        'fcm.googleapis.com/*' => Http::response('', 410),
        'updates.push.services.mozilla.com/*' => Http::response('', 404),
        'web.push.apple.com/*' => Http::response('', 201),
    ]);

    $result = app(WebPushSender::class)->send($this->luis->pushSubscriptions()->orderBy('id')->get(), new WebPushMessage('Título', 'Texto', '/chat', 'conversation-1'));

    expect($result)->toBe(['sent' => 1, 'expired' => 2, 'failed' => 0])
        ->and($this->luis->pushSubscriptions()->pluck('endpoint')->all())->toBe(['https://web.push.apple.com/viva']);
});

it('un fallo del servicio o de la red cuenta; tras 5 seguidos la suscripción se borra', function () {
    $subscription = ($this->subscription)($this->luis);
    Http::fake(['fcm.googleapis.com/*' => Http::sequence()
        ->push('', 500)
        ->whenEmpty(Http::failedConnection())]);
    $send = fn () => app(WebPushSender::class)->send($this->luis->pushSubscriptions()->get(), new WebPushMessage('T', null, '/chat'));

    expect($send())->toBe(['sent' => 0, 'expired' => 0, 'failed' => 1])
        ->and($subscription->fresh()->failures)->toBe(1);

    foreach (range(1, 3) as $attempt) {
        $send();
    }
    expect($subscription->fresh()->failures)->toBe(4);

    $send();
    expect(PushSubscription::query()->count())->toBe(0);
});

it('nunca llama a un endpoint que no sea de un servicio de push (aunque llegara a la base de datos)', function () {
    ($this->subscription)($this->luis, 'https://intranet.audaxstudio.com/hook');
    Http::fake();

    $result = app(WebPushSender::class)->send($this->luis->pushSubscriptions()->get(), new WebPushMessage('T', null, '/chat'));

    expect($result['expired'])->toBe(1)->and(PushSubscription::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('el canal no hace nada con quien no tiene navegadores suscritos', function () {
    Http::fake();

    app(WebPushChannel::class)->send($this->luis, new ChatDirectMessageNotification(1, 1, null, 'Ana', 'Hola', push: true));

    Http::assertNothingSent();
});

it('al cerrar sesión, ese navegador deja de recibir los avisos de esa persona', function () {
    // La misma sesión (cookie) al suscribirse y al cerrar sesión, como en el navegador.
    $session = str_repeat('a1B2c3D4e5', 4);
    $this->withCredentials()->withCookie((string) config('session.cookie'), $session);

    $this->actingAs($this->luis)->postJson('/avisos-navegador/suscripciones', ($this->payload)())->assertCreated();
    expect(PushSubscription::query()->sole()->session_hash)->toBe(hash('sha256', $session));
    PushSubscription::query()->create([
        'user_id' => $this->luis->id,
        'endpoint' => 'https://web.push.apple.com/movil',
        'endpoint_hash' => PushSubscription::hashEndpoint('https://web.push.apple.com/movil'),
        'public_key' => $this->browser->publicKey(),
        'auth_token' => $this->browser->authToken(),
        'session_hash' => hash('sha256', 'otra-sesion'),
    ]);

    $this->actingAs($this->luis)->post('/logout');

    expect(PushSubscription::query()->pluck('endpoint')->all())->toBe(['https://web.push.apple.com/movil']);
});

it('el comando genera claves VAPID válidas, las imprime y no escribe ningún fichero', function () {
    $env = base_path('.env');
    $before = is_file($env) ? md5_file($env) : null;

    expect(Artisan::call('push:vapid-keys'))->toBe(0);
    $output = Artisan::output();

    preg_match('/^VAPID_PUBLIC_KEY=(\S+)$/m', $output, $public);
    preg_match('/^VAPID_PRIVATE_KEY=(\S+)$/m', $output, $private);
    expect($public[1] ?? null)->not->toBeNull()
        ->and($output)->toContain('VAPID_SUBJECT="mailto:');

    config(['services.webpush.public_key' => $public[1], 'services.webpush.private_key' => $private[1]]);
    expect(WebPushConfig::enabled())->toBeTrue()
        ->and(is_file($env) ? md5_file($env) : null)->toBe($before)
        ->and(Artisan::call('push:vapid-keys', ['--check' => true]))->toBe(0);

    config(['services.webpush.public_key' => 'cambiar-esta-clave-publica-vapid']);
    expect(Artisan::call('push:vapid-keys', ['--check' => true]))->toBe(1);
});
