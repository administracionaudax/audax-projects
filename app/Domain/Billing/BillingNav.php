<?php

namespace App\Domain\Billing;

use App\Models\HoldedSyncRun;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Lo que la navegación de Facturación enseña en todas las páginas (D-405 y D-409), en la prop
 * compartida `billing`, solo para quien tiene view-billing:
 * - `review`: el contador de «Por revisar» de la barra lateral: los contactos de Holded sin casar o
 *   casados por un nombre parecido, por confirmar, más las facturas sin proyecto ni bolsa (I5),
 * - `sync`: la última lectura de Holded (cuándo, si falló y cuándo fue la última buena), para el
 *   estado discreto de la cabecera de cada pantalla (R6).
 * Son datos de toda la agencia, no de cada persona: se guardan en la caché un minuto y se olvidan al
 * casar un contacto o al empezar y acabar una lectura (BillingServiceProvider).
 */
final class BillingNav
{
    public const string CACHE_KEY = 'billing.nav';

    public const int TTL_SECONDS = 60;

    /**
     * @return array{review: int, sync: array{status: string, started_at: string, finished_at: string|null, last_ok_at: string|null}|null}|null
     */
    public static function for(?User $user): ?array
    {
        if (! BillingAccess::viewsBilling($user)) {
            return null;
        }

        /** @var array{review: int, sync: array{status: string, started_at: string, finished_at: string|null, last_ok_at: string|null}|null} */
        return Cache::remember(self::CACHE_KEY, self::TTL_SECONDS, fn (): array => [
            'review' => self::reviewCount(),
            'sync' => self::sync(),
        ]);
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Lo que hay en «Por revisar» (I5, D-413): contactos sin casar (ni descartados) más los casados
     * por un nombre parecido, y facturas sin proyecto ni bolsa (dos consultas).
     */
    public static function reviewCount(): int
    {
        return array_sum(ReviewInbox::counts());
    }

    /**
     * La última lectura de Holded y, si no acabó bien, cuándo fue la última que sí (dos consultas
     * como mucho).
     *
     * @return array{status: string, started_at: string, finished_at: string|null, last_ok_at: string|null}|null
     */
    public static function sync(): ?array
    {
        $run = HoldedSyncRun::query()->orderByDesc('started_at')->orderByDesc('id')->first();

        if ($run === null) {
            return null;
        }

        $ok = $run->status === HoldedSyncRun::OK
            ? $run
            : HoldedSyncRun::query()->where('status', HoldedSyncRun::OK)->orderByDesc('started_at')->orderByDesc('id')->first();

        return [
            'status' => $run->status,
            'started_at' => $run->started_at->utc()->toIso8601ZuluString(),
            'finished_at' => $run->finished_at?->utc()->toIso8601ZuluString(),
            'last_ok_at' => $ok === null ? null : ($ok->finished_at ?? $ok->started_at)->utc()->toIso8601ZuluString(),
        ];
    }
}
