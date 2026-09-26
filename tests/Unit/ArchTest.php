<?php

arch('sin funciones de depuración en la aplicación')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'ddd'])
    ->not->toBeUsed();

arch('los enums de roles y permisos son backed enums de string')
    ->expect('App\Enums')
    ->toBeStringBackedEnums();

arch('los middleware tienen método handle')
    ->expect('App\Http\Middleware')
    ->toHaveMethod('handle');

arch('las fuentes de búsqueda implementan SearchSource')
    ->expect('App\Search\Sources')
    ->toImplement('App\Search\SearchSource');
