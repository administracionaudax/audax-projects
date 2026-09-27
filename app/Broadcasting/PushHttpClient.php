<?php

namespace App\Broadcasting;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Cliente HTTP (PSR-18) de Web Push sobre el cliente de Laravel: timeouts cortos, sin seguir
 * redirecciones (SSRF) y simulable en los tests con Http::fake().
 * Los códigos 4xx y 5xx vuelven como respuesta (la librería los clasifica: 404 y 410 = caducada);
 * solo un fallo de red lanza excepción.
 */
final class PushHttpClient implements ClientInterface
{
    public function __construct(private readonly ?int $timeout = null) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            if (strcasecmp((string) $name, 'Host') !== 0) {
                $headers[(string) $name] = implode(', ', $values);
            }
        }

        $timeout = max(1, $this->timeout ?? (int) config('services.webpush.timeout', 10));

        $pending = Http::timeout($timeout)
            ->connectTimeout(min(5, $timeout))
            ->withoutRedirecting()
            ->withHeaders($headers);

        $body = (string) $request->getBody();
        if ($body !== '') {
            $pending = $pending->withBody($body, $request->getHeaderLine('Content-Type') ?: 'application/octet-stream');
        }

        try {
            $response = $pending->send($request->getMethod(), (string) $request->getUri());
        } catch (ConnectionException $exception) {
            throw new PushTransportException($exception->getMessage(), $request, $exception);
        }

        return $response->toPsrResponse();
    }
}
