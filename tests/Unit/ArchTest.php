<?php

use Pest\Arch\Repositories\ObjectsRepository;

/*
 * Memoria (la batería del servidor corre en un solo proceso, con memory_limit=512M):
 * el plugin de arquitectura guarda en un estático (ObjectsRepository::$instance) el árbol
 * sintáctico completo de todo lo que analiza y no lo suelta nunca. Con todo app/ de una vez
 * eran ~225 MB (Fase 10) que se arrastraban hasta el último test y fragmentaban el montón.
 * Por eso las funciones de depuración se buscan por trozos (un espacio de nombres por caso) y
 * el estático se vacía después de cada test: el pico queda en el trozo más grande.
 */
afterEach(function (): void {
    (new ReflectionProperty(ObjectsRepository::class, 'instance'))->setValue(null, null);
    gc_collect_cycles();
    gc_mem_caches();
});

/**
 * Los espacios de nombres del código propio (lo mismo que recorre ->not->toBeUsed():
 * App, Database\Factories y Database\Seeders), con App\Domain y App\Http partidos un nivel más.
 *
 * @return list<string>
 */
function archNamespaces(): array
{
    $root = dirname(__DIR__, 2);
    $children = static fn (string $dir, string $namespace): array => array_values(array_unique(array_map(
        static fn (string $path): string => $namespace.'\\'.basename($path, '.php'),
        glob("{$root}/{$dir}/*") ?: [],
    )));

    return [
        ...array_values(array_diff($children('app', 'App'), ['App\\Domain', 'App\\Http'])),
        ...$children('app/Domain', 'App\\Domain'),
        ...$children('app/Http', 'App\\Http'),
        'Database\\Factories',
        'Database\\Seeders',
    ];
}

test('sin funciones de depuración en la aplicación', function (string $namespace): void {
    expect($namespace)->not->toUse(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'ddd']);
})->with(archNamespaces());

arch('los enums de roles y permisos son backed enums de string')
    ->expect('App\Enums')
    ->toBeStringBackedEnums();

arch('los middleware tienen método handle')
    ->expect('App\Http\Middleware')
    ->toHaveMethod('handle');

arch('las fuentes de búsqueda implementan SearchSource')
    ->expect('App\Search\Sources')
    ->toImplement('App\Search\SearchSource');
