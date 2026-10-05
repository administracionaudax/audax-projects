<?php

namespace App\Domain\Import\WeeklySync\Dump;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;

/**
 * Descarga del Storage de Supabase con la clave secreta (D-213): GET
 * {url}/storage/v1/object/{bucket}/{ruta}, que lee también los buckets privados. Solo GET: nunca
 * sube, mueve ni borra nada. La clave va en las cabeceras `apikey` y `Authorization` (vale la
 * service_role de siempre y la sb_secret_… nueva) y nunca sale en los errores.
 */
final class SupabaseStorage implements WeeklySyncStorage
{
    public function __construct(
        private readonly string $projectUrl,
        #[SensitiveParameter] private readonly string $serviceKey,
    ) {}

    public function download(string $bucket, string $path, string $target): bool
    {
        $url = rtrim($this->projectUrl, '/').'/storage/v1/object/'.rawurlencode($bucket).'/'.self::encodePath($path);

        try {
            $response = Http::withHeaders([
                'apikey' => $this->serviceKey,
                'Authorization' => 'Bearer '.$this->serviceKey,
            ])
                ->timeout(600)
                ->connectTimeout(20)
                ->retry(3, 2000, throw: false)
                ->sink($target)
                ->get($url);
        } catch (ConnectionException) {
            @unlink($target);

            throw new StorageDownloadFailed("No se ha podido conectar con el Storage al descargar {$bucket}/{$path}.");
        }

        if ($response->successful()) {
            // Con Http::fake (tests) el cuerpo no pasa por el sink.
            if (! is_file($target) || (filesize($target) === 0 && $response->body() !== '')) {
                file_put_contents($target, $response->body());
            }

            return true;
        }

        @unlink($target);

        // Supabase responde 400 con «not_found» (y a veces 404) cuando el objeto no existe.
        if ($response->status() === 404 || ($response->status() === 400 && str_contains(strtolower($response->body()), 'not_found'))) {
            return false;
        }

        throw new StorageDownloadFailed("El Storage ha respondido {$response->status()} al descargar {$bucket}/{$path}. Revisa WEEKLYSYNC_URL y WEEKLYSYNC_SERVICE_KEY.");
    }

    /**
     * Cada segmento codificado por separado (las barras se conservan).
     */
    public static function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
