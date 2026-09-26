<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Support\Facades\Vite;

test('todas las respuestas llevan las cabeceras de seguridad y noindex', function (string $path) {
    $this->get($path)
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
        ->assertHeader('Content-Security-Policy');
})->with(['/login', '/health', '/', '/ruta-que-no-existe']);

test('la CSP usa un nonce que coincide con el del script inline de la página', function () {
    $response = $this->get('/login')->assertOk();

    $csp = $response->headers->get('Content-Security-Policy');

    expect($csp)->toMatch("/script-src 'self' 'nonce-[A-Za-z0-9]+'/")
        ->toContain("default-src 'self'")
        ->toContain("style-src 'self' 'unsafe-inline'")
        ->toContain("img-src 'self' data: blob:")
        ->toContain("font-src 'self' data:")
        ->toContain("connect-src 'self'")
        ->toContain("frame-ancestors 'none'")
        ->toContain("base-uri 'self'")
        ->toContain("form-action 'self'")
        ->toContain("object-src 'none'")
        ->not->toContain('unsafe-eval');

    preg_match("/'nonce-([A-Za-z0-9]+)'/", (string) $csp, $matches);

    $response->assertSee('<script nonce="'.$matches[1].'">', false);
});

test('el nonce cambia en cada petición', function () {
    $first = $this->get('/login')->headers->get('Content-Security-Policy');
    $second = $this->get('/login')->headers->get('Content-Security-Policy');

    expect($first)->not->toBe($second);
});

test('HSTS solo se envía por HTTPS', function () {
    $this->get('http://localhost/login')->assertHeaderMissing('Strict-Transport-Security');

    $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});

test('la vista incluye manifest, theme-color y robots noindex', function () {
    $this->get('/login')
        ->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false)
        ->assertSee('<meta name="theme-color" content="#001B39">', false)
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
});

test('en desarrollo la CSP permite el servidor de Vite', function () {
    $this->withVite();

    $hot = storage_path('framework/testing/vite-hot-'.uniqid());
    @mkdir(dirname($hot), 0777, true);
    file_put_contents($hot, 'http://[::1]:5173');
    Vite::useHotFile($hot);

    try {
        $csp = (new SecurityHeaders)->contentSecurityPolicy('abc');
    } finally {
        unlink($hot);
    }

    expect($csp)->toContain("script-src 'self' 'nonce-abc' http://[::1]:5173")
        ->toContain("style-src 'self' 'unsafe-inline' http://[::1]:5173")
        ->toContain("connect-src 'self' http://[::1]:5173 ws://[::1]:5173");
});

test('sin servidor de Vite la CSP no admite orígenes externos', function () {
    $this->withVite();
    Vite::useHotFile(storage_path('framework/testing/no-existe-hot'));

    expect((new SecurityHeaders)->contentSecurityPolicy('abc'))->not->toContain('http');
});
