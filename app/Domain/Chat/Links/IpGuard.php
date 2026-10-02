<?php

namespace App\Domain\Chat\Links;

/**
 * ¿Se puede conectar a esta IP desde el servidor? (SSRF, D-069). Solo direcciones públicas:
 * - IPv4: todo salvo las redes especiales del RFC 6890 y afines (privadas, locales, link-local
 *   con los metadatos de nube 169.254.169.254, CGNAT, documentación, pruebas, multicast,
 *   reservadas y difusión),
 * - IPv6: solo unicast global (2000::/3) y, dentro de ella, nada de documentación, Teredo, 6to4,
 *   NAT64 ni asignaciones del IETF; las IPv4 mapeadas (::ffff:a.b.c.d) se juzgan como IPv4.
 * Además, PHP tiene que considerarla de rango global (FILTER_FLAG_GLOBAL_RANGE): doble criterio.
 */
final class IpGuard
{
    /**
     * @var list<string>
     */
    public const array BLOCKED_V4 = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.31.196.0/24',
        '192.52.193.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '192.175.48.0/24',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    /**
     * Dentro de 2000::/3 (lo único admitido), lo que no es una dirección pública corriente.
     *
     * @var list<string>
     */
    public const array BLOCKED_V6 = [
        '2001::/23',
        '2001:db8::/32',
        '2002::/16',
        '3fff::/20',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
    ];

    public static function isPublic(string $ip): bool
    {
        $ip = trim($ip, '[]');
        $binary = @inet_pton($ip);

        if ($binary === false) {
            return false;
        }

        if (strlen($binary) === 4) {
            return self::isPublicV4($binary) && self::phpSaysGlobal($ip);
        }

        // IPv4 mapeada en IPv6 (::ffff:a.b.c.d): se juzga la IPv4.
        if (str_starts_with($binary, str_repeat("\0", 10)."\xff\xff")) {
            $v4 = (string) inet_ntop(substr($binary, 12));

            return self::isPublic($v4);
        }

        if (! self::inCidr($binary, '2000::/3')) {
            return false;
        }

        foreach (self::BLOCKED_V6 as $cidr) {
            if (self::inCidr($binary, $cidr)) {
                return false;
            }
        }

        return self::phpSaysGlobal($ip);
    }

    private static function isPublicV4(string $binary): bool
    {
        foreach (self::BLOCKED_V4 as $cidr) {
            if (self::inCidr($binary, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private static function phpSaysGlobal(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * ¿La dirección (binaria, de inet_pton) está en el bloque CIDR? Solo compara la misma familia.
     */
    public static function inCidr(string $binary, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $networkBinary = @inet_pton($network);

        if ($networkBinary === false || strlen($networkBinary) !== strlen($binary)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if (strncmp($binary, $networkBinary, $bytes) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($binary[$bytes]) & $mask) === (ord($networkBinary[$bytes]) & $mask);
    }
}
