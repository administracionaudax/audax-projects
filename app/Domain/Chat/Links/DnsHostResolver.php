<?php

namespace App\Domain\Chat\Links;

/**
 * Resolución con el DNS del sistema: registros A y AAAA (y, si el sistema no da registros,
 * gethostbynamel para IPv4).
 */
final class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null;

                if (is_string($ip) && $ip !== '') {
                    $ips[] = $ip;
                }
            }
        }

        if ($ips === []) {
            $ipv4 = @gethostbynamel($host);
            $ips = is_array($ipv4) ? $ipv4 : [];
        }

        return array_values(array_unique($ips));
    }
}
