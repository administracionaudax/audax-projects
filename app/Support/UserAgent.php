<?php

namespace App\Support;

/**
 * Resumen legible de un user agent (navegador y sistema operativo) sin librerías externas.
 * Solo sirve para mostrar; nunca para tomar decisiones de seguridad.
 */
final class UserAgent
{
    /**
     * @return array{browser: string, platform: string}
     */
    public static function summarize(?string $userAgent): array
    {
        $ua = (string) $userAgent;

        return [
            'browser' => self::browser($ua),
            'platform' => self::platform($ua),
        ];
    }

    private static function browser(string $ua): string
    {
        return match (true) {
            $ua === '' => 'Desconocido',
            str_contains($ua, 'Edg/') || str_contains($ua, 'EdgA/') || str_contains($ua, 'EdgiOS/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'SamsungBrowser/') => 'Samsung Internet',
            str_contains($ua, 'Firefox/') || str_contains($ua, 'FxiOS/') => 'Firefox',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS/') || str_contains($ua, 'Chromium/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Otro navegador',
        };
    }

    private static function platform(string $ua): string
    {
        return match (true) {
            $ua === '' => 'Desconocido',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') || str_contains($ua, 'iPod') => 'iOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Otro sistema',
        };
    }
}
