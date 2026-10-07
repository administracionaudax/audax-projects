<?php

namespace App\Domain\People;

use App\Models\ClockCorrection;
use App\Models\ClockEvent;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * Huellas del registro de jornada (D-332 y D-335). Cada fila de `clock_events` guarda la huella de
 * la anterior de la misma persona (`prev_hash`) y la suya (`hash`): SHA-256 de una cadena canónica
 * con TODAS sus columnas de contenido. Cambiar, quitar o intercalar una fila rompe la cadena desde
 * ahí, y RegisterIntegrity lo detecta. Las correcciones decididas se sellan igual (`hash`).
 *
 * La versión (`v1`) va dentro de la cadena: si un día cambia el formato, las filas antiguas se
 * siguen comprobando con el suyo. Los instantes van en UTC con segundos (las columnas no guardan
 * fracciones de segundo).
 */
final class RegisterHasher
{
    public const string VERSION = 'v1';

    /** Huella «anterior» de la primera fila de cada persona. */
    public const string GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    public function event(ClockEvent $event): string
    {
        return hash('sha256', implode("\n", [
            self::VERSION,
            'clock_event',
            (string) $event->user_id,
            (string) $event->seq,
            $event->kind->value,
            self::instant($event->occurred_at),
            self::instant($event->recorded_at),
            $event->work_mode->value ?? '',
            $event->pause_type->value ?? '',
            $event->source->value,
            (string) ($event->voided_event_id ?? ''),
            (string) ($event->correction_id ?? ''),
            (string) ($event->created_by ?? ''),
            (string) ($event->ip_hash ?? ''),
            (string) ($event->user_agent ?? ''),
            $event->prev_hash,
        ]));
    }

    public function correction(ClockCorrection $correction): string
    {
        return hash('sha256', implode("\n", [
            self::VERSION,
            'clock_correction',
            (string) $correction->id,
            (string) $correction->user_id,
            $correction->date->toDateString(),
            (string) $correction->proposed_by,
            $correction->reason,
            self::canonicalJson($correction->voids),
            self::canonicalJson($correction->adds),
            $correction->status->value,
            (string) ($correction->decided_by ?? ''),
            self::instant($correction->decided_at),
            (string) ($correction->decision_note ?? ''),
            (string) ($correction->dispute_reason ?? ''),
            self::instant($correction->created_at),
        ]));
    }

    /**
     * Huella de la IP con la clave de la app (HMAC): sirve para comparar fichajes entre sí sin
     * guardar la IP (minimización, L-11; D-332).
     */
    public static function ipHash(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        return hash_hmac('sha256', $ip, (string) config('app.key'));
    }

    private static function instant(?DateTimeInterface $instant): string
    {
        if ($instant === null) {
            return '';
        }

        $utc = $instant instanceof CarbonInterface ? $instant->copy()->utc() : (new \DateTimeImmutable('@'.$instant->getTimestamp()));

        return $utc->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * JSON con las claves ordenadas: el mismo contenido da la misma huella aunque la base de datos
     * lo devuelva con otro orden (jsonb).
     */
    private static function canonicalJson(mixed $value): string
    {
        return (string) json_encode(self::sortKeys($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sortKeys(...), $value);
    }
}
