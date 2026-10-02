<?php

namespace App\Domain\System;

use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Estado de las copias de seguridad (D-076) según el fichero JSON que escriben los scripts del
 * servidor (formato en config/backups.php). Devuelve los problemas que hay que avisar al admin:
 * - la copia de la noche falló (last_backup_ok = false),
 * - la última copia correcta tiene más de max_age_hours horas,
 * - la última prueba de restauración falló,
 * - la copia externa falló (solo si está activada: existen las claves last_offsite_*).
 *
 * Tolerante: faltan o sobran claves → se comprueba lo que haya; sin fichero → nada que avisar;
 * fichero ilegible o valores de otro tipo → no se avisa de las copias, pero queda en el log.
 */
final class BackupStatus
{
    public const string BACKUP_FAILED = 'backup_failed';

    public const string BACKUP_STALE = 'backup_stale';

    public const string RESTORE_FAILED = 'restore_failed';

    public const string OFFSITE_FAILED = 'offsite_failed';

    /**
     * Problemas encontrados: motivo → datos para el texto del aviso (fechas en Madrid).
     *
     * @return array<string, array<string, string|int>>
     */
    public function problems(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $status = $this->read();

        if ($status === null) {
            return [];
        }

        $problems = [];
        $lastBackup = $this->instant($status, 'last_backup_at');
        $maxAge = max(1, (int) config('backups.max_age_hours', 36));

        if ($this->flag($status, 'last_backup_ok') === false) {
            $problems[self::BACKUP_FAILED] = [
                'failed_at' => $this->display($this->instant($status, 'last_backup_failed_at')),
                'last_ok_at' => $this->display($lastBackup),
            ];
        } elseif ($lastBackup !== null && $lastBackup->lt($now->subHours($maxAge))) {
            $problems[self::BACKUP_STALE] = [
                'last_ok_at' => $this->display($lastBackup),
                'hours' => $maxAge,
            ];
        }

        if ($this->flag($status, 'last_restore_check_ok') === false) {
            $problems[self::RESTORE_FAILED] = [
                'checked_at' => $this->display($this->instant($status, 'last_restore_check_at')),
            ];
        }

        if ($this->flag($status, 'last_offsite_ok') === false) {
            $problems[self::OFFSITE_FAILED] = [
                'failed_at' => $this->display($this->instant($status, 'last_offsite_failed_at')),
                'last_ok_at' => $this->display($this->instant($status, 'last_offsite_at')),
            ];
        }

        return $problems;
    }

    /**
     * Contenido del fichero, o null si no existe o no se puede usar (esto último, en el log).
     *
     * @return array<string, mixed>|null
     */
    private function read(): ?array
    {
        $path = (string) config('backups.status_path');

        if ($path === '' || ! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);
        $status = is_string($contents) ? json_decode($contents, true) : null;

        if (! is_array($status) || array_is_list($status)) {
            Log::warning('app:check-storage: el fichero de estado de las copias no es un objeto JSON; no se avisa de las copias.', ['path' => $path]);

            return null;
        }

        return $status;
    }

    /**
     * Booleano JSON de la clave, o null si falta o es de otro tipo (esto último, en el log).
     *
     * @param  array<string, mixed>  $status
     */
    private function flag(array $status, string $key): ?bool
    {
        if (! array_key_exists($key, $status) || $status[$key] === null) {
            return null;
        }

        if (! is_bool($status[$key])) {
            Log::warning("app:check-storage: {$key} no es un booleano en el estado de las copias; se ignora.");

            return null;
        }

        return $status[$key];
    }

    /**
     * Instante ISO 8601 de la clave, o null si falta o no se entiende (esto último, en el log).
     *
     * @param  array<string, mixed>  $status
     */
    private function instant(array $status, string $key): ?CarbonImmutable
    {
        $value = $status[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $value) === 1) {
            try {
                return CarbonImmutable::parse($value)->utc();
            } catch (Throwable) {
                // Se registra abajo.
            }
        }

        Log::warning("app:check-storage: {$key} no es una fecha ISO 8601 en el estado de las copias; se ignora.");

        return null;
    }

    private function display(?CarbonImmutable $instant): string
    {
        if ($instant === null) {
            $unknown = __('system.backups.unknown_date');

            return is_string($unknown) ? $unknown : '—';
        }

        return $instant->setTimezone(LocalTime::timezone())->format('d/m/Y H:i');
    }
}
