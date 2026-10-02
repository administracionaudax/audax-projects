<?php

namespace App\Domain\Chat\Links;

use RuntimeException;

/**
 * La URL (o una de sus redirecciones) no se puede visitar desde el servidor: esquema o puerto no
 * admitidos, credenciales, nombre que no resuelve o que resuelve a una IP no pública (SSRF).
 */
final class UnsafeUrl extends RuntimeException {}
