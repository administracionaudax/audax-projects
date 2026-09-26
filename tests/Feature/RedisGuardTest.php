<?php

use App\Providers\AppServiceProvider;

/**
 * Configura Redis y el entorno, y ejecuta la guarda del AppServiceProvider.
 */
function runRedisGuard(string $env, array $redis): void
{
    app()['env'] = $env;

    // Todas las conexiones: default, cache y la "horizon" que Horizon copia de default al arrancar.
    $connections = array_diff(array_keys((array) config('database.redis')), ['client', 'options', 'clusters']);

    foreach ($connections as $connection) {
        config([
            "database.redis.{$connection}.port" => $redis['port'],
            "database.redis.{$connection}.password" => $redis['password'],
        ]);
    }

    (new AppServiceProvider(app()))->guardSharedRedis();
}

test('en staging aborta si Redis apunta al 6379 compartido', function () {
    expect(fn () => runRedisGuard('staging', ['port' => '6379', 'password' => 'secreto']))
        ->toThrow(RuntimeException::class, 'Valkey propio');
});

test('en staging aborta si Redis no tiene contraseña', function (?string $password) {
    expect(fn () => runRedisGuard('staging', ['port' => '16379', 'password' => $password]))
        ->toThrow(RuntimeException::class);
})->with([null, '']);

test('en producción también aborta con el puerto compartido', function () {
    expect(fn () => runRedisGuard('production', ['port' => 6379, 'password' => 'secreto']))
        ->toThrow(RuntimeException::class);
});

test('con el Valkey propio y contraseña arranca con normalidad', function () {
    runRedisGuard('staging', ['port' => '16379', 'password' => 'secreto']);

    expect(true)->toBeTrue();
});

test('en local y testing no se exige Valkey', function (string $env) {
    runRedisGuard($env, ['port' => '6379', 'password' => null]);

    expect(true)->toBeTrue();
})->with(['local', 'testing']);

test('por defecto la configuración apunta al puerto 16379', function () {
    expect((string) config('database.redis.default.port'))->toBe('16379')
        ->and((string) config('database.redis.cache.port'))->toBe('16379');
});
