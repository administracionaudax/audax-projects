<?php

/*
| Orígenes que pueden abrir el WebSocket de Reverb (D-120): nunca '*'. REVERB_ALLOWED_ORIGINS
| (hosts separados por comas) o, si no está o está vacío, el host de APP_URL.
*/

function reverb_allowed_origins(array $env): array
{
    $saved = [];
    foreach ($env as $key => $value) {
        $saved[$key] = getenv($key);
        $value === null ? putenv($key) : putenv("{$key}={$value}");
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
        } else {
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
    }

    try {
        return (require base_path('config/reverb.php'))['apps']['apps'][0]['allowed_origins'];
    } finally {
        foreach ($saved as $key => $value) {
            $value === false ? putenv($key) : putenv("{$key}={$value}");
            if ($value === false) {
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                $_ENV[$key] = $_SERVER[$key] = $value;
            }
        }
    }
}

it('por defecto solo admite el host de APP_URL', function () {
    expect(reverb_allowed_origins(['REVERB_ALLOWED_ORIGINS' => null, 'APP_URL' => 'https://projects.audaxstudio.com']))
        ->toBe(['projects.audaxstudio.com'])
        ->and(reverb_allowed_origins(['REVERB_ALLOWED_ORIGINS' => '', 'APP_URL' => 'http://127.0.0.1:8000']))
        ->toBe(['127.0.0.1']);
});

it('REVERB_ALLOWED_ORIGINS admite varios hosts y nunca deja la lista vacía ni con «*» por defecto', function () {
    expect(reverb_allowed_origins(['REVERB_ALLOWED_ORIGINS' => 'projects.audaxstudio.com, otra.audaxstudio.com ,', 'APP_URL' => 'https://x.test']))
        ->toBe(['projects.audaxstudio.com', 'otra.audaxstudio.com'])
        ->and(reverb_allowed_origins(['REVERB_ALLOWED_ORIGINS' => null, 'APP_URL' => 'https://projects.audaxstudio.com']))
        ->not->toContain('*');
});
