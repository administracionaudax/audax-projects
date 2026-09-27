<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad y noindex en todas las respuestas (SPEC §15, D-002).
 *
 * La CSP usa un nonce por petición que Vite añade a sus etiquetas (Vite::useCspNonce) y que
 * app.blade.php pone en su script inline. En desarrollo (npm run dev) se permite además el
 * origen del servidor de Vite leído de public/hot.
 */
class SecurityHeaders
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $headers = $response->headers;

        $headers->set('X-Robots-Tag', 'noindex, nofollow');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // El micrófono, solo para la propia app: grabar audios en el chat (Fase 6).
        $headers->set('Permissions-Policy', 'camera=(), microphone=(self), geolocation=()');

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        if (! $headers->has('Content-Security-Policy') && ! $this->isExcludedFromCsp($request)) {
            $headers->set('Content-Security-Policy', $this->contentSecurityPolicy($nonce));
        }

        return $response;
    }

    public function contentSecurityPolicy(string $nonce): string
    {
        [$devOrigins, $devSockets] = $this->viteDevServerOrigins();

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-{$nonce}'", ...$devOrigins],
            'style-src' => ["'self'", "'unsafe-inline'", ...$devOrigins],
            'img-src' => ["'self'", 'data:', 'blob:', ...$devOrigins],
            'font-src' => ["'self'", 'data:', ...$devOrigins],
            'connect-src' => ["'self'", ...$devOrigins, ...$devSockets],
            'frame-ancestors' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'object-src' => ["'none'"],
            'worker-src' => ["'self'"],
            'manifest-src' => ["'self'"],
        ];

        return implode('; ', array_map(
            fn (string $directive, array $sources): string => $directive.' '.implode(' ', $sources),
            array_keys($directives),
            $directives,
        ));
    }

    /**
     * Horizon (solo admin) pinta su propio JS inline sin nonce: se excluye de la CSP.
     */
    private function isExcludedFromCsp(Request $request): bool
    {
        $horizonPath = trim((string) config('horizon.path', 'horizon'), '/');

        return $horizonPath !== '' && ($request->is($horizonPath) || $request->is($horizonPath.'/*'));
    }

    /**
     * Orígenes del servidor de desarrollo de Vite (http y ws), si está en marcha.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function viteDevServerOrigins(): array
    {
        if (! Vite::isRunningHot()) {
            return [[], []];
        }

        $hot = trim((string) file_get_contents(Vite::hotFile()));
        $parts = parse_url($hot);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return [[], []];
        }

        $host = str_contains($parts['host'], ':') && ! str_starts_with($parts['host'], '[')
            ? '['.$parts['host'].']'
            : $parts['host'];
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $socketScheme = $parts['scheme'] === 'https' ? 'wss' : 'ws';

        return [
            ["{$parts['scheme']}://{$host}{$port}"],
            ["{$socketScheme}://{$host}{$port}"],
        ];
    }
}
