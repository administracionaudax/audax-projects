<?php

use App\Models\WeeklyCycle;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
| Límites de peticiones por acción (D-222, hallazgo 3 de la revisión de seguridad de la Fase 10):
| `throttle:N,1` sin prefijo usa una sola clave por persona para TODAS las rutas, así que el sondeo de
| una ruta permisiva agotaba el límite de una estricta (429 en «Generar», «Enviar» o «Preguntar»).
| Ahora cada ruta lleva su prefijo (su nombre).
*/

it('cada ruta con un límite numérico tiene su propio contador (prefijo), sin compartirlo por accidente', function () {
    // Los prefijos compartidos a propósito (varias rutas de una misma acción).
    $shared = ['home-layout', 'chat-conversations', 'chat-groups', 'google-oauth', 'push-write', 'realtime-viewing'];
    $prefixes = [];

    foreach (Route::getRoutes() as $route) {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! (Str::startsWith($middleware, 'throttle:') || Str::startsWith($middleware, ThrottleRequests::class.':'))) {
                continue;
            }

            $args = explode(',', Str::after($middleware, ':'));

            if (! is_numeric($args[0])) {
                continue; // limitador con nombre (RateLimiter::for)
            }

            expect(count($args))->toBe(3, "{$route->uri()} usa {$middleware} sin prefijo");
            $prefixes[$args[2]][] = $route->getName() ?? $route->uri();
        }
    }

    foreach ($prefixes as $prefix => $routes) {
        if (! in_array($prefix, $shared, true)) {
            expect(array_unique($routes))->toHaveCount(1, "el prefijo {$prefix} lo comparten ".implode(', ', array_unique($routes)));
        }
    }
});

it('sondear el estado del informe no agota el límite de «Generar»', function () {
    Queue::fake();
    $manager = userWithRole('admin');
    $cycle = WeeklyCycle::factory()->active()->create();

    for ($i = 0; $i < 15; $i++) {
        $this->actingAs($manager)->getJson("/weeklies/{$cycle->id}/informe/estado")->assertOk();
    }

    $this->actingAs($manager)->postJson("/weeklies/{$cycle->id}/informe")->assertStatus(202);
});

it('sondear la respuesta del asistente no agota el límite de «Preguntar»', function () {
    Queue::fake();
    $me = userWithRole('employee');
    $id = $this->actingAs($me)->postJson('/ia/preguntas', ['question' => '¿Cómo va?'])->assertStatus(202)->json('question.id');

    for ($i = 0; $i < 25; $i++) {
        $this->actingAs($me)->getJson("/ia/preguntas/{$id}")->assertOk();
    }

    // La siguiente pregunta no da 429 (da 422 porque la anterior sigue en curso, D-222).
    $this->actingAs($me)->postJson('/ia/preguntas', ['question' => '¿Y ahora?'])->assertUnprocessable();
});

it('el autoguardado de Mi weekly no agota el límite de «Enviar»', function () {
    $me = userWithRole('employee');
    $cycle = WeeklyCycle::factory()->active()->create();

    for ($i = 0; $i < 35; $i++) {
        $this->actingAs($me)->putJson("/mi-espacio/weeklies/{$cycle->id}", ['entries' => [['client_id' => null, 'body' => "Borrador {$i}"]]])->assertOk();
    }

    $this->actingAs($me)->post("/mi-espacio/weeklies/{$cycle->id}/enviar", ['entries' => [['client_id' => null, 'body' => 'Hecho']]])
        ->assertRedirect("/mi-espacio?semana={$cycle->id}")
        ->assertInertiaFlash('toast.message', __('weeklies.flash.submitted'));
});
