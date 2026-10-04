<?php

namespace App\Domain\Integrations\Google;

use App\Domain\Reports\Delivery\GeneratedReportFile;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sube el XLSX de un informe al Drive de quien lo pide convertido a hoja de cálculo nativa de
 * Google (D-142): subida multipart de la API de Drive v3 con los metadatos `{name, mimeType:
 * application/vnd.google-apps.spreadsheet}`, que es lo que le pide a Google que lo convierta. Con
 * el alcance `drive.file`, la app solo ve los archivos que crea ella. Devuelve el enlace para
 * abrir la hoja (webViewLink).
 */
final class GoogleSheetsUploader
{
    public const string UPLOAD_URL = 'https://www.googleapis.com/upload/drive/v3/files';

    public const string SPREADSHEET_MIME = 'application/vnd.google-apps.spreadsheet';

    public function __construct(private readonly GoogleOAuth $oauth) {}

    /**
     * @throws GoogleNotConnected sin conexión (o GoogleReconnectRequired si Google la retiró)
     * @throws GoogleUnavailable
     */
    public function upload(User $user, GeneratedReportFile $file, string $title): string
    {
        $contents = @file_get_contents($file->path);

        if ($contents === false) {
            throw new RuntimeException("No se puede leer el fichero del informe: {$file->path}");
        }

        $response = $this->send($this->oauth->accessToken($user), $title, $file, $contents);

        // El token puede caducar o revocarse entre la comprobación y la subida: se renueva una vez.
        if ($response->status() === 401) {
            $response = $this->send($this->oauth->accessToken($user, force: true), $title, $file, $contents);

            if ($response->status() === 401) {
                $user->googleConnection()->delete();

                throw new GoogleReconnectRequired('Google Drive rejected a fresh access token');
            }
        }

        $link = $response->json('webViewLink');

        if (! $response->successful() || ! is_string($link) || ! Str::startsWith($link, 'https://')) {
            throw new GoogleUnavailable('Google Drive upload: '.$response->status());
        }

        return $link;
    }

    private function send(string $token, string $title, GeneratedReportFile $file, string $contents): Response
    {
        $boundary = 'audax-'.Str::random(32);
        $metadata = json_encode(['name' => $title, 'mimeType' => self::SPREADSHEET_MIME], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $body = "--{$boundary}\r\n"
            ."Content-Type: application/json; charset=UTF-8\r\n\r\n"
            .$metadata."\r\n"
            ."--{$boundary}\r\n"
            ."Content-Type: {$file->format->mime()}\r\n\r\n"
            .$contents."\r\n"
            ."--{$boundary}--\r\n";

        try {
            return Http::withToken($token)
                ->acceptJson()
                ->timeout(GoogleOAuth::timeout())
                ->withBody($body, "multipart/related; boundary={$boundary}")
                ->post(self::UPLOAD_URL.'?'.http_build_query(['uploadType' => 'multipart', 'fields' => 'id,webViewLink']));
        } catch (ConnectionException $exception) {
            throw new GoogleUnavailable('Google Drive upload: '.$exception->getMessage(), previous: $exception);
        }
    }
}
