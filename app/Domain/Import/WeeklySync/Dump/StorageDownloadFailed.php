<?php

namespace App\Domain\Import\WeeklySync\Dump;

use RuntimeException;

/**
 * El Storage respondió con un error que no es «no existe» (credenciales, red, 5xx…). El mensaje
 * nunca lleva la clave: solo el bucket, la ruta y el código HTTP.
 */
final class StorageDownloadFailed extends RuntimeException {}
