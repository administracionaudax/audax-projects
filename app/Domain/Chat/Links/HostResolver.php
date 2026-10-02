<?php

namespace App\Domain\Chat\Links;

/**
 * Resolución DNS para la previsualización de enlaces. Detrás de una interfaz para poder simular
 * resoluciones en los tests de SSRF (un nombre público que resuelve a 127.0.0.1, etc.).
 */
interface HostResolver
{
    /**
     * Todas las IP (A y AAAA) del nombre, o una lista vacía si no resuelve.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
