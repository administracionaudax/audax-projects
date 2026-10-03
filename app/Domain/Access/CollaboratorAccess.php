<?php

namespace App\Domain\Access;

use Illuminate\Routing\Route;
use Illuminate\Support\Str;

/**
 * Rutas internas abiertas a un colaborador externo (D-134): una lista explícita de patrones de
 * nombres de ruta (config/collaborators.php). Todo lo demás está cerrado por defecto.
 */
final class CollaboratorAccess
{
    public static function allowsRouteName(?string $name): bool
    {
        if ($name === null || $name === '') {
            return false;
        }

        /** @var list<string> $patterns */
        $patterns = config('collaborators.routes', []);

        return Str::is($patterns, $name);
    }

    public static function allows(?Route $route): bool
    {
        if ($route === null) {
            return false;
        }

        if (self::allowsRouteName($route->getName())) {
            return true;
        }

        /** @var list<string> $uris */
        $uris = config('collaborators.uris', []);

        return in_array(ltrim($route->uri(), '/'), $uris, true);
    }
}
