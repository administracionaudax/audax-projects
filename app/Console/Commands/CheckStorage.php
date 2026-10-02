<?php

namespace App\Console\Commands;

use App\Domain\System\BackupStatus;
use App\Domain\System\DiskUsage;
use App\Models\Attachment;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\System\BackupWarningNotification;
use App\Notifications\System\StorageWarningNotification;
use App\Notifications\System\SystemWarning;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Avisos de almacenamiento y copias (SPEC §15, D-076), cada día a las 09:00 de Madrid:
 * - el disco del almacenamiento de la app por encima de disk_warning_percent,
 * - los adjuntos (suma de attachments.size) por encima de attachments_warning_gb, si está fijado,
 * - la copia de la noche fallida o atrasada, la prueba de restauración fallida o la copia externa
 *   fallida (App\Domain\System\BackupStatus, fichero de config('backups.status_path')).
 *
 * Avisa a los admins activos como mucho una vez al día por motivo (aunque el comando se lance
 * varias veces). Los avisos son obligatorios (NotificationCatalog: system.disk_space y
 * system.backup_failed).
 */
#[Signature('app:check-storage')]
#[Description('Avisa al admin si el disco o los adjuntos pasan del umbral o si las copias de seguridad fallan o se atrasan')]
class CheckStorage extends Command
{
    public function handle(DiskUsage $disk, BackupStatus $backups): int
    {
        $now = CarbonImmutable::now();
        $warnings = [];

        $space = $disk->measure(storage_path());
        $threshold = (int) Setting::get('disk_warning_percent', 85);

        if ($space === null) {
            Log::warning('app:check-storage: no se ha podido medir el disco del almacenamiento.', ['path' => storage_path()]);
        } elseif ($space->usedPercent() >= $threshold) {
            $warnings[] = new StorageWarningNotification(StorageWarningNotification::DISK, [
                'percent' => self::number($space->usedPercent()),
                'threshold' => $threshold,
                'free' => self::bytes($space->freeBytes),
            ]);
        }

        $attachmentsGb = Setting::get('attachments_warning_gb');
        $attachmentsBytes = null;

        if (is_numeric($attachmentsGb) && (int) $attachmentsGb > 0) {
            // Solo los que siguen en el disco: al borrar un adjunto se borra su fichero.
            $attachmentsBytes = (int) Attachment::query()->sum('size');

            if ($attachmentsBytes >= (int) $attachmentsGb * 1024 ** 3) {
                $warnings[] = new StorageWarningNotification(StorageWarningNotification::ATTACHMENTS, [
                    'size' => self::bytes($attachmentsBytes),
                    'threshold' => (int) $attachmentsGb,
                ]);
            }
        }

        foreach ($backups->problems($now) as $reason => $details) {
            $warnings[] = new BackupWarningNotification($reason, $details);
        }

        $sent = $this->send($warnings, $now);

        Log::info('app:check-storage', [
            'disk_used_percent' => $space?->usedPercent(),
            'disk_warning_percent' => $threshold,
            'attachments_bytes' => $attachmentsBytes,
            'warnings' => array_map(fn (SystemWarning $warning): string => $warning->reason, $warnings),
            'sent' => $sent,
        ]);

        $this->components->info($warnings === []
            ? 'Todo en orden: disco, adjuntos y copias.'
            : 'Avisos: '.implode(', ', array_map(fn (SystemWarning $warning): string => $warning->reason, $warnings)).'. Enviados hoy: '.($sent === [] ? 'ninguno (ya se avisó)' : implode(', ', $sent)).'.');

        return self::SUCCESS;
    }

    /**
     * Envía a los admins activos los avisos que aún no se han enviado hoy (día de Madrid).
     *
     * @param  list<SystemWarning>  $warnings
     * @return list<string> motivos enviados
     */
    private function send(array $warnings, CarbonImmutable $now): array
    {
        if ($warnings === []) {
            return [];
        }

        $admins = User::query()->active()->role('admin')->get();

        if ($admins->isEmpty()) {
            Log::warning('app:check-storage: hay avisos, pero ningún admin activo que los reciba.');

            return [];
        }

        $today = $now->setTimezone(LocalTime::timezone());
        $ttl = $today->addDay()->startOfDay()->addHour();
        $sent = [];

        foreach ($warnings as $warning) {
            // Una vez al día por motivo: Cache::add solo gana la primera vez de cada día.
            if (! Cache::add("app:check-storage:{$warning->reason}:{$today->toDateString()}", true, $ttl)) {
                continue;
            }

            Notification::send($admins, $warning);
            $sent[] = $warning->reason;
        }

        return $sent;
    }

    /** 85.3 → «85,3». */
    private static function number(float $value): string
    {
        return str_replace('.', ',', (string) round($value, 1));
    }

    /** Bytes → «12,3 GB». */
    private static function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return self::number($value).' '.$units[$unit];
    }
}
