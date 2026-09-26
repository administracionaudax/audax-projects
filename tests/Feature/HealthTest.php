<?php

test('/health responde 200 con las comprobaciones', function () {
    $this->getJson('/health')
        ->assertOk()
        ->assertExactJson([
            'status' => 'ok',
            'checks' => [
                'database' => 'ok',
                'redis' => 'skipped',
                'queue' => 'ok',
                'disk' => 'ok',
                'session' => 'skipped',
            ],
            'redis_port_ok' => true,
        ])
        ->assertHeader('Cache-Control', 'no-store, private');
});

test('/health no expone versiones, rutas ni secretos', function () {
    $content = $this->get('/health')->getContent();

    expect($content)
        ->not->toContain(app()->version())
        ->not->toContain(PHP_VERSION)
        ->not->toContain(base_path())
        ->not->toContain('Laravel')
        ->not->toContain((string) config('app.key'))
        ->not->toContain((string) config('database.default'));
});

test('/health responde 503 si falla la base de datos', function () {
    $default = config('database.default');

    config([
        'database.connections.health_broken' => [
            'driver' => 'sqlite',
            'database' => '/ruta/que/no/existe/base.sqlite',
            'prefix' => '',
        ],
        'database.default' => 'health_broken',
    ]);

    try {
        $response = $this->getJson('/health');
    } finally {
        config(['database.default' => $default]);
    }

    $response->assertStatus(503)
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.database', 'fail');

    expect($response->getContent())->not->toContain('/ruta/que/no/existe');
});

test('/health comprueba Redis si la app lo usa y marca el fallo', function () {
    config([
        'cache.default' => 'redis',
        'database.redis.default.host' => '127.0.0.1',
        'database.redis.default.port' => 1,
        'database.redis.default.timeout' => 0.2,
        'database.redis.default.max_retries' => 0,
    ]);

    $this->getJson('/health')
        ->assertStatus(503)
        ->assertJsonPath('checks.redis', 'fail');
});

test('/health exige el puerto 16379 de Redis fuera de local y testing', function () {
    app()['env'] = 'staging';
    config(['database.redis.default.port' => '6379']);

    $this->getJson('/health')
        ->assertStatus(503)
        ->assertJsonPath('redis_port_ok', false);

    config(['database.redis.default.port' => '16379', 'database.redis.cache.port' => '16379']);

    $this->getJson('/health')->assertJsonPath('redis_port_ok', true);
});

test('/health se degrada fuera de local y testing si las sesiones no van en la base de datos', function () {
    app()['env'] = 'staging';
    config([
        'database.redis.default.port' => '16379',
        'database.redis.cache.port' => '16379',
        'session.driver' => 'file',
    ]);

    $this->getJson('/health')
        ->assertStatus(503)
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.session', 'fail');

    config(['session.driver' => 'database']);

    $this->getJson('/health')
        ->assertOk()
        ->assertJsonPath('checks.session', 'ok');
});
