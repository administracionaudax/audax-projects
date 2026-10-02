<?php

namespace App\Domain\Chat\Links;

use GuzzleHttp\Psr7\StreamWrapper;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Previsualización de enlaces con protección SSRF estricta (D-069). Por cada salto (la URL y
 * cada redirección, 3 como mucho):
 * 1. solo http y https, puertos 80 y 443, sin credenciales y con un nombre de dominio de verdad
 *    (nada de «localhost», nombres de una sola etiqueta ni IP en notación numérica rara),
 * 2. se resuelve el nombre (A y AAAA) y TODAS sus IP tienen que ser públicas (IpGuard) ANTES de
 *    conectar; la conexión va fijada a esa IP (CURLOPT_RESOLVE) para que un segundo DNS no la
 *    cambie (DNS rebinding), sin proxy,
 * 3. 3 s en total para todo, 512 KB como mucho (LimitedBody corta la descarga) y solo text/html.
 * Devuelve título, descripción y dominio (nunca una imagen remota), o null. Se recuerda en caché.
 */
final class LinkPreviewFetcher
{
    public function __construct(
        private readonly HostResolver $resolver,
        private readonly LinkPreviewParser $parser,
        private readonly Cache $cache,
    ) {}

    /**
     * Con caché: 24 h si hay previsualización y 1 h si no (para no insistir con una URL rota).
     */
    public function preview(string $url): ?LinkPreview
    {
        $key = 'chat:link-preview:'.sha1($url);
        $cached = $this->cache->get($key);

        if (is_array($cached)) {
            return ($cached['none'] ?? false) === true ? null : LinkPreview::fromArray($cached);
        }

        $preview = $this->fetch($url);

        $this->cache->put(
            $key,
            $preview?->toArray() ?? ['none' => true],
            $preview === null ? now()->addHour() : now()->addHours((int) config('link_previews.cache_hours', 24)),
        );

        return $preview;
    }

    /**
     * Sin caché: siempre va a la red (con todas las comprobaciones).
     */
    public function fetch(string $url): ?LinkPreview
    {
        $deadline = microtime(true) + (float) config('link_previews.timeout', 3);
        $redirects = max(0, (int) config('link_previews.max_redirects', 3));
        $current = $url;

        try {
            for ($hop = 0; $hop <= $redirects; $hop++) {
                $target = $this->target($current);
                $remaining = $deadline - microtime(true);

                if ($remaining <= 0.05) {
                    return null;
                }

                $page = $this->request($target, $remaining);

                if ($page === null) {
                    return null;
                }

                if ($page['status'] >= 300 && $page['status'] < 400) {
                    if ($page['location'] === '') {
                        return null;
                    }

                    $current = self::resolveLocation($target['url'], $page['location']);

                    continue;
                }

                return $page['status'] >= 200 && $page['status'] < 300
                    ? $this->build($url, $target['host'], $page)
                    : null;
            }
        } catch (UnsafeUrl) {
            return null;
        }

        // Demasiadas redirecciones.
        return null;
    }

    /**
     * Valida la URL y resuelve su nombre. Lanza UnsafeUrl si no se puede visitar.
     *
     * @return array{url: string, host: string, port: int, ip: string}
     */
    public function target(string $url): array
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new UnsafeUrl('URL no válida.');
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new UnsafeUrl('Solo http y https.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeUrl('Sin credenciales en la URL.');
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        if (! in_array($port, [80, 443], true)) {
            throw new UnsafeUrl('Solo los puertos 80 y 443.');
        }

        $host = strtolower(rtrim(trim($parts['host'], '[]'), '.'));

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ips = [$host];
            $urlHost = str_contains($host, ':') ? "[{$host}]" : $host;
        } else {
            $host = $this->asciiHost($host);
            $ips = $this->resolver->resolve($host);
            $urlHost = $host;
        }

        if ($ips === []) {
            throw new UnsafeUrl('El nombre no resuelve.');
        }

        foreach ($ips as $ip) {
            if (! IpGuard::isPublic($ip)) {
                throw new UnsafeUrl('Dirección no pública.');
            }
        }

        $path = ($parts['path'] ?? '') === '' ? '/' : $parts['path'];
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $explicitPort = isset($parts['port']) ? ':'.$port : '';

        return [
            'url' => "{$scheme}://{$urlHost}{$explicitPort}{$path}{$query}",
            'host' => $host,
            'port' => $port,
            'ip' => $ips[0],
        ];
    }

    /**
     * Nombre de dominio en ASCII (punycode) con al menos dos etiquetas y un dominio de primer
     * nivel alfabético: descarta «localhost», «intranet», los .local/.internal y las IP escritas
     * en notación decimal, octal o hexadecimal («2130706433», «0x7f.1»).
     */
    private function asciiHost(string $host): string
    {
        if (preg_match('/[^\x20-\x7E]/', $host) === 1) {
            $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : false;

            if (! is_string($ascii)) {
                throw new UnsafeUrl('Nombre de dominio no válido.');
            }

            $host = strtolower($ascii);
        }

        $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

        if (strlen($host) > 253 || preg_match("/^(?:{$label}\\.)+(?:[a-z][a-z0-9-]{0,61}[a-z0-9])$/", $host) !== 1) {
            throw new UnsafeUrl('Nombre de dominio no válido.');
        }

        foreach (['localhost', 'local', 'internal', 'intranet', 'lan', 'home', 'corp', 'localdomain', 'arpa', 'test', 'invalid', 'example'] as $reserved) {
            if ($host === $reserved || str_ends_with($host, '.'.$reserved)) {
                throw new UnsafeUrl('Dominio reservado.');
            }
        }

        return $host;
    }

    /**
     * Una petición GET sin seguir redirecciones, fijada a la IP comprobada. Devuelve estado,
     * cabeceras útiles y hasta 512 KB del cuerpo; null si falla o no es HTML.
     *
     * @param  array{url: string, host: string, port: int, ip: string}  $target
     * @return array{status: int, type: string, location: string, body: string}|null
     */
    private function request(array $target, float $timeout): ?array
    {
        $body = new LimitedBody(max(1, (int) config('link_previews.max_bytes', 512 * 1024)));
        $sink = StreamWrapper::getResource($body);
        $headers = null;
        // Con un nombre, cURL se conecta a la IP ya comprobada (sin volver a resolver).
        $pin = filter_var($target['host'], FILTER_VALIDATE_IP) !== false
            ? []
            : [CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $target['host'], $target['port'], str_contains($target['ip'], ':') ? "[{$target['ip']}]" : $target['ip'])]];

        try {
            $response = Http::withOptions([
                'allow_redirects' => false,
                'protocols' => ['http', 'https'],
                'proxy' => '',
                'sink' => $sink,
                'curl' => $pin,
                'on_headers' => function (ResponseInterface $response) use (&$headers): void {
                    $headers = $response;
                    $status = $response->getStatusCode();

                    // Nada que no sea HTML se descarga (solo se miran las cabeceras).
                    if ($status >= 200 && $status < 300 && ! self::isHtml($response->getHeaderLine('Content-Type'))) {
                        throw new UnsafeUrl('No es HTML.');
                    }
                },
            ])
                ->withUserAgent((string) config('link_previews.user_agent'))
                ->accept('text/html,application/xhtml+xml;q=0.9')
                ->withHeaders(['Accept-Language' => 'es-ES,es;q=0.9,en;q=0.6'])
                ->connectTimeout(min($timeout, 2.0))
                ->timeout($timeout)
                ->get($target['url']);

            return $this->page($response->status(), $response->header('Content-Type'), $response->header('Location'), $body);
        } catch (ConnectionException|RequestException $exception) {
            // Se ha cortado al llegar al tope: vale lo leído si las cabeceras eran de una página HTML.
            if ($body->overflowed() && $headers instanceof ResponseInterface) {
                return $this->page($headers->getStatusCode(), $headers->getHeaderLine('Content-Type'), '', $body);
            }

            return null;
        } catch (Throwable) {
            return null;
        } finally {
            if (is_resource($sink)) {
                fclose($sink);
            }
        }
    }

    /**
     * @return array{status: int, type: string, location: string, body: string}|null
     */
    private function page(int $status, string $type, string $location, LimitedBody $body): ?array
    {
        if ($status >= 200 && $status < 300 && ! self::isHtml($type)) {
            return null;
        }

        return ['status' => $status, 'type' => $type, 'location' => trim($location), 'body' => $body->contents()];
    }

    public static function isHtml(string $contentType): bool
    {
        return str_starts_with(strtolower(trim($contentType)), 'text/html');
    }

    /**
     * @param  array{status: int, type: string, location: string, body: string}  $page
     */
    private function build(string $url, string $host, array $page): ?LinkPreview
    {
        preg_match('/charset\s*=\s*"?([\w.:-]+)/i', $page['type'], $charset);
        $parsed = $this->parser->parse($page['body'], $charset[1] ?? null);

        if ($parsed['title'] === null) {
            return null;
        }

        return new LinkPreview($url, $parsed['title'], $parsed['description'], self::displayDomain($host));
    }

    /**
     * Dominio para mostrar: sin «www.» y, si es un dominio internacional, en Unicode.
     */
    public static function displayDomain(string $host): string
    {
        if (str_contains($host, 'xn--') && function_exists('idn_to_utf8')) {
            $unicode = idn_to_utf8($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            $host = is_string($unicode) ? $unicode : $host;
        }

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * URL absoluta de una cabecera Location (absoluta, relativa al esquema, a la raíz o a la ruta).
     */
    public static function resolveLocation(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $authority = ($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($location, '//')) {
            return "{$scheme}:{$location}";
        }

        if (str_starts_with($location, '/')) {
            return "{$scheme}://{$authority}{$location}";
        }

        $path = $parts['path'] ?? '/';
        $directory = substr($path, 0, (int) strrpos($path, '/') + 1);

        return "{$scheme}://{$authority}".($directory === '' ? '/' : $directory).$location;
    }
}
