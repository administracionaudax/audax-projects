<?php

namespace App\Domain\Integrations\Google;

use App\Domain\Reports\Delivery\GeneratedReportFile;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sube el XLSX de un informe al Drive de quien lo pide convertido a hoja de cálculo nativa de
 * Google (D-142): los metadatos `{name, mimeType: application/vnd.google-apps.spreadsheet}` son lo
 * que le pide a Google que lo convierta. Con el alcance `drive.file`, la app solo ve los archivos
 * que crea ella. Devuelve el enlace para abrir la hoja (webViewLink).
 *
 * Dos caminos, según el tamaño:
 * - hasta 5 MB, subida multipart (una petición con los metadatos y el fichero), que es el máximo
 *   que admite Drive por ese camino,
 * - por encima, subida reanudable: un POST de inicio con los metadatos (y el tamaño y el tipo del
 *   contenido) devuelve la URI de la sesión en `Location`, y el fichero va en PUT por trozos de
 *   8 MB (múltiplos de 256 KiB, como pide Drive), leídos del disco uno a uno. Google responde 308
 *   con `Range` a cada trozo intermedio y 200 con la hoja al último. Si un trozo falla por la red o
 *   por un 5xx, se pregunta a Google cuánto ha guardado y se sigue desde ahí (MAX_RESUMES veces);
 *   cualquier otro error termina en GoogleUnavailable (502).
 *
 * Si Drive rechaza el token (401), se renueva una vez y se reintenta; si lo vuelve a rechazar, la
 * conexión se borra y hay que volver a conectar.
 */
final class GoogleSheetsUploader
{
    public const string UPLOAD_URL = 'https://www.googleapis.com/upload/drive/v3/files';

    public const string SPREADSHEET_MIME = 'application/vnd.google-apps.spreadsheet';

    /** Máximo de la subida multipart de Drive: por encima, subida reanudable. */
    public const int MULTIPART_MAX_BYTES = 5 * 1024 * 1024;

    /** Trozo de la subida reanudable: 8 MB, múltiplo de 256 KiB (32 × 256 KiB). */
    public const int CHUNK_BYTES = 32 * self::CHUNK_UNIT;

    /** Drive exige trozos múltiplos de 256 KiB (salvo el último). */
    public const int CHUNK_UNIT = 256 * 1024;

    /** Veces que se retoma una subida reanudable tras un error de red o un 5xx. */
    public const int MAX_RESUMES = 3;

    private const string FIELDS = 'id,webViewLink';

    public function __construct(private readonly GoogleOAuth $oauth) {}

    /**
     * @throws GoogleNotConnected sin conexión (o GoogleReconnectRequired si Google la retiró)
     * @throws GoogleUnavailable
     */
    public function upload(User $user, GeneratedReportFile $file, string $title): string
    {
        $size = is_file($file->path) ? @filesize($file->path) : false;

        if ($size === false) {
            throw new RuntimeException("No se puede leer el fichero del informe: {$file->path}");
        }

        $response = $size > self::MULTIPART_MAX_BYTES
            ? $this->resumable($user, $file, $title, $size)
            : $this->multipart($user, $file, $title);

        $link = $response->json('webViewLink');

        if (! $response->successful() || ! is_string($link) || ! Str::startsWith($link, 'https://')) {
            throw new GoogleUnavailable('Google Drive upload: '.$response->status());
        }

        return $link;
    }

    private function multipart(User $user, GeneratedReportFile $file, string $title): Response
    {
        $contents = @file_get_contents($file->path);

        if ($contents === false) {
            throw new RuntimeException("No se puede leer el fichero del informe: {$file->path}");
        }

        $boundary = 'audax-'.Str::random(32);

        $body = "--{$boundary}\r\n"
            ."Content-Type: application/json; charset=UTF-8\r\n\r\n"
            .self::metadata($title)."\r\n"
            ."--{$boundary}\r\n"
            ."Content-Type: {$file->format->mime()}\r\n\r\n"
            .$contents."\r\n"
            ."--{$boundary}--\r\n";

        return $this->authorized($user, fn (string $token): Response => $this->send(
            fn () => $this->http($token)
                ->withBody($body, "multipart/related; boundary={$boundary}")
                ->post(self::UPLOAD_URL.'?'.http_build_query(['uploadType' => 'multipart', 'fields' => self::FIELDS])),
        ));
    }

    private function resumable(User $user, GeneratedReportFile $file, string $title, int $size): Response
    {
        $token = '';

        $start = $this->authorized($user, function (string $fresh) use (&$token, $file, $title, $size): Response {
            $token = $fresh;

            return $this->send(fn () => $this->http($fresh)
                ->withHeaders([
                    'X-Upload-Content-Type' => $file->format->mime(),
                    'X-Upload-Content-Length' => (string) $size,
                ])
                ->withBody(self::metadata($title), 'application/json; charset=UTF-8')
                ->post(self::UPLOAD_URL.'?'.http_build_query(['uploadType' => 'resumable', 'fields' => self::FIELDS])));
        });

        $session = $start->header('Location');

        // La URI de la sesión lleva el token en cada trozo: solo se acepta si es de Drive.
        if (! $start->successful() || ! Str::startsWith($session, self::UPLOAD_URL.'?')) {
            throw new GoogleUnavailable('Google Drive resumable start: '.$start->status());
        }

        $handle = @fopen($file->path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("No se puede leer el fichero del informe: {$file->path}");
        }

        try {
            return $this->sendChunks($user, $token, $session, $handle, $size, $file->format->mime());
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     */
    private function sendChunks(User $user, string $token, string $session, $handle, int $size, string $mime): Response
    {
        $offset = 0;
        $resumes = 0;
        $refreshed = false;

        while (true) {
            if (fseek($handle, $offset) !== 0) {
                throw new RuntimeException('No se puede leer el fichero del informe');
            }

            $chunk = (string) fread($handle, max(1, min(self::CHUNK_BYTES, $size - $offset)));
            $end = $offset + strlen($chunk) - 1;

            $response = $this->attempt(fn () => $this->http($token)
                ->withHeaders(['Content-Range' => "bytes {$offset}-{$end}/{$size}"])
                ->withBody($chunk, $mime)
                ->put($session));

            if ($response?->successful()) {
                return $response;
            }

            if ($response?->status() === 308) {
                $next = self::persisted($response);

                if ($next <= $offset || $next >= $size) {
                    throw new GoogleUnavailable("Google Drive resumable upload stalled at {$offset}/{$size}");
                }

                $offset = $next;

                continue;
            }

            // Token caducado a mitad de una subida larga: se renueva una vez y se repite el trozo.
            if ($response?->status() === 401 && ! $refreshed) {
                $refreshed = true;
                $token = $this->oauth->accessToken($user, force: true);

                continue;
            }

            if (($response === null || $response->serverError()) && $resumes < self::MAX_RESUMES) {
                $resumes++;
                $status = $this->attempt(fn () => $this->http($token)
                    ->withHeaders(['Content-Range' => "bytes */{$size}"])
                    ->withBody('', $mime)
                    ->put($session));

                if ($status?->successful()) {
                    return $status;
                }

                // Sin respuesta tampoco a la consulta: se repite el mismo trozo.
                if ($status === null) {
                    continue;
                }

                if ($status->status() === 308) {
                    $offset = self::persisted($status);

                    continue;
                }
            }

            throw new GoogleUnavailable('Google Drive resumable upload: '.($response?->status() ?? 'connection').", {$offset}/{$size}");
        }
    }

    /**
     * Hace la petición con el token de $user; si Drive lo rechaza (401), lo renueva una vez y la
     * repite. Si lo vuelve a rechazar, Google ha retirado el acceso: se borra la conexión.
     *
     * @param  callable(string): Response  $call
     *
     * @throws GoogleNotConnected
     * @throws GoogleUnavailable
     */
    private function authorized(User $user, callable $call): Response
    {
        $response = $call($this->oauth->accessToken($user));

        // El token puede caducar o revocarse entre la comprobación y la subida.
        if ($response->status() === 401) {
            $response = $call($this->oauth->accessToken($user, force: true));

            if ($response->status() === 401) {
                $connection = $user->googleConnection()->first();

                if ($connection !== null) {
                    GoogleDisconnector::forgetRevoked($connection);
                }

                throw new GoogleReconnectRequired('Google Drive rejected a fresh access token');
            }
        }

        return $response;
    }

    private function http(string $token): PendingRequest
    {
        return Http::withToken($token)
            ->acceptJson()
            ->timeout(GoogleOAuth::timeout())
            // El 308 de Drive es «sigue subiendo», no una redirección.
            ->withoutRedirecting();
    }

    /**
     * @param  callable(): Response  $call
     *
     * @throws GoogleUnavailable sin respuesta (red o tiempo agotado)
     */
    private function send(callable $call): Response
    {
        return $this->attempt($call) ?? throw new GoogleUnavailable('Google Drive upload: no connection');
    }

    /**
     * La respuesta, o null si no la hay (red o tiempo agotado).
     *
     * @param  callable(): Response  $call
     */
    private function attempt(callable $call): ?Response
    {
        try {
            return $call();
        } catch (ConnectionException) {
            return null;
        }
    }

    /** Siguiente byte que espera Drive según la cabecera `Range: bytes=0-N` de un 308 (sin ella, 0). */
    private static function persisted(Response $response): int
    {
        return preg_match('/^bytes=0-(\d+)$/', trim($response->header('Range')), $match) === 1 ? (int) $match[1] + 1 : 0;
    }

    private static function metadata(string $title): string
    {
        return json_encode(['name' => $title, 'mimeType' => self::SPREADSHEET_MIME], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
