<?php

use App\Support\UserAgent;

test('resume el navegador y el sistema del user agent', function (?string $ua, string $browser, string $platform) {
    expect(UserAgent::summarize($ua))->toBe(['browser' => $browser, 'platform' => $platform]);
})->with([
    'Chrome en Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36', 'Chrome', 'Windows'],
    'Edge en Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0', 'Edge', 'Windows'],
    'Safari en macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15', 'Safari', 'macOS'],
    'Firefox en Linux' => ['Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0', 'Firefox', 'Linux'],
    'Safari en iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1', 'Safari', 'iOS'],
    'Chrome en Android' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36', 'Chrome', 'Android'],
    'Firefox en iOS' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/131.0 Mobile/15E148 Safari/605.1.15', 'Firefox', 'iOS'],
    'vacío' => [null, 'Desconocido', 'Desconocido'],
    'raro' => ['curl/8.0', 'Otro navegador', 'Otro sistema'],
]);
