<?php

use App\Http\Middleware\SecurityHeaders;
use App\Models\User;

/*
| La CSP deja conectar con el WebSocket de Reverb (connect-src) solo cuando hay tiempo real, y
| sin abrir nada más: el origen exacto de config/realtime.php.
*/

beforeEach(function () {
    $this->connectSrc = function (): string {
        $policy = app(SecurityHeaders::class)->contentSecurityPolicy('nonce');
        preg_match('/connect-src ([^;]+)/', $policy, $match);

        return $match[1] ?? '';
    };
});

it('sin tiempo real, connect-src se queda en self', function () {
    config(['realtime.enabled' => false]);

    expect(($this->connectSrc)())->toBe("'self'");
});

it('en el servidor (wss por el 443) añade el origen del WebSocket sin puerto', function () {
    config(['realtime.enabled' => true, 'realtime.host' => 'projects.audaxstudio.com', 'realtime.port' => 443, 'realtime.scheme' => 'https']);

    expect(($this->connectSrc)())->toBe("'self' wss://projects.audaxstudio.com");
});

it('en local y en la CI (ws en otro puerto) añade el puerto', function () {
    config(['realtime.enabled' => true, 'realtime.host' => '127.0.0.1', 'realtime.port' => 8080, 'realtime.scheme' => 'http']);

    expect(($this->connectSrc)())->toBe("'self' ws://127.0.0.1:8080");
});

it('una página del chat lleva a la vez el micrófono para la app (C3) y el WebSocket de Reverb (C2)', function () {
    config(['realtime.enabled' => true, 'realtime.host' => 'projects.audaxstudio.com', 'realtime.port' => 443, 'realtime.scheme' => 'https']);

    $response = $this->actingAs(User::factory()->employee()->create())->get('/chat')->assertOk();

    expect($response->headers->get('Permissions-Policy'))->toBe('camera=(), microphone=(self), geolocation=()')
        ->and($response->headers->get('Content-Security-Policy'))->toContain("connect-src 'self' wss://projects.audaxstudio.com;");
});
