<?php

use App\Domain\Notifications\NotificationCatalog;
use App\Domain\Notifications\NotificationPreferences;
use App\Domain\System\DiskSpace;
use App\Domain\System\DiskUsage;
use App\Domain\System\NativeDiskUsage;
use App\Models\Attachment;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\System\BackupWarningNotification;
use App\Notifications\System\StorageWarningNotification;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/*
| Avisos de almacenamiento y copias (SPEC §15, D-076): app:check-storage avisa a los admins activos
| si el disco o los adjuntos pasan del umbral, o si las copias fallan o se atrasan, como mucho una
| vez al día por motivo. El fichero de estado de las copias lo escriben los scripts del servidor.
*/

beforeEach(function () {
    $this->travelTo('2026-10-05 07:00:00');
    Notification::fake();

    $this->admin = User::factory()->admin()->create();
    $this->otherAdmin = User::factory()->admin()->create();
    $this->inactiveAdmin = User::factory()->admin()->inactive()->create();
    $this->employee = User::factory()->employee()->create();

    // Disco simulado: total y libre en GB.
    $this->disk = fn (int $totalGb, int $freeGb) => $this->app->instance(DiskUsage::class, new class($totalGb, $freeGb) implements DiskUsage
    {
        public function __construct(private readonly int $total, private readonly int $free) {}

        public function measure(string $path): ?DiskSpace
        {
            return new DiskSpace($this->total * 1024 ** 3, $this->free * 1024 ** 3);
        }
    });

    $this->statusPath = storage_path('framework/testing/backup-status-'.bin2hex(random_bytes(4)).'.json');
    config(['backups.status_path' => $this->statusPath]);
    $this->status = fn (array|string $status) => file_put_contents($this->statusPath, is_string($status) ? $status : json_encode($status));

    ($this->disk)(100, 50);
});

afterEach(function () {
    @unlink($this->statusPath);
});

test('con el disco por debajo del umbral, sin adjuntos de más y sin fichero de copias, no avisa', function () {
    $this->artisan('app:check-storage')->assertSuccessful();

    Notification::assertNothingSent();
});

test('avisa a los admins activos cuando el disco pasa del umbral, una vez al día', function () {
    ($this->disk)(200, 20);

    $this->artisan('app:check-storage')->assertSuccessful();

    Notification::assertSentTo([$this->admin, $this->otherAdmin], StorageWarningNotification::class, function (StorageWarningNotification $notification, array $channels, User $notifiable): bool {
        $data = $notification->toArray($notifiable);

        return $notification->reason === StorageWarningNotification::DISK
            && $data['kind'] === 'system.disk_space'
            && $data['title'] === 'El disco del servidor está al 90 %'
            && $data['body'] === 'Supera el aviso del 85 %. Quedan 20 GB libres: libera espacio o amplía el disco antes de que se llene.'
            && $data['url'] === '/admin/privacidad'
            && $data['icon'] === 'triangle-alert';
    });
    Notification::assertNotSentTo([$this->inactiveAdmin, $this->employee], StorageWarningNotification::class);

    // Otra vez el mismo día: nada nuevo.
    $this->artisan('app:check-storage')->assertSuccessful();
    Notification::assertSentToTimes($this->admin, StorageWarningNotification::class, 1);

    // Al día siguiente (de Madrid), otra vez.
    $this->travelTo('2026-10-05 22:30:00');
    $this->artisan('app:check-storage')->assertSuccessful();
    Notification::assertSentToTimes($this->admin, StorageWarningNotification::class, 2);
});

test('el umbral del disco sale del ajuste disk_warning_percent', function () {
    ($this->disk)(100, 20);
    Setting::set('disk_warning_percent', 95);

    $this->artisan('app:check-storage')->assertSuccessful();
    Notification::assertNothingSent();

    Setting::set('disk_warning_percent', 80);

    $this->artisan('app:check-storage')->assertSuccessful();
    Notification::assertSentTo($this->admin, StorageWarningNotification::class);
});

test('avisa de los adjuntos solo si hay umbral y lo pasan (sin contar los borrados)', function () {
    Attachment::factory()->create(['size' => 3 * 1024 ** 3]);
    $deleted = Attachment::factory()->create(['size' => 5 * 1024 ** 3]);
    $deleted->delete();

    $this->artisan('app:check-storage')->assertSuccessful();
    Notification::assertNothingSent();

    Setting::set('attachments_warning_gb', 4);
    $this->artisan('app:check-storage')->assertSuccessful();
    Notification::assertNothingSent();

    Setting::set('attachments_warning_gb', 2);
    $this->artisan('app:check-storage')->assertSuccessful();

    Notification::assertSentTo($this->admin, StorageWarningNotification::class, fn (StorageWarningNotification $notification, array $channels, User $notifiable): bool => $notification->reason === StorageWarningNotification::ATTACHMENTS
        && $notification->toArray($notifiable)['title'] === 'Los adjuntos ocupan 3 GB'
        && $notification->toArray($notifiable)['body'] === 'Superan el aviso de 2 GB. Revisa los adjuntos de los proyectos cerrados o sube el umbral en Privacidad y datos.');
});

test('las copias correctas y recientes no avisan', function () {
    ($this->status)([
        'last_backup_at' => '2026-10-05T01:41:02Z',
        'last_backup_ok' => true,
        'last_restore_check_at' => '2026-10-01T03:00:00Z',
        'last_restore_check_ok' => true,
        'otra_clave' => 'se ignora',
    ]);

    $this->artisan('app:check-storage')->assertSuccessful();

    Notification::assertNothingSent();
});

test('avisa si la copia de la noche falló', function () {
    ($this->status)([
        'last_backup_at' => '2026-10-04T01:41:02Z',
        'last_backup_ok' => false,
        'last_backup_failed_at' => '2026-10-05T01:40:00Z',
    ]);

    $this->artisan('app:check-storage')->assertSuccessful();

    Notification::assertSentTo($this->admin, BackupWarningNotification::class, function (BackupWarningNotification $notification, array $channels, User $notifiable): bool {
        $data = $notification->toArray($notifiable);

        return $notification->reason === 'backup_failed'
            && $data['kind'] === 'system.backup_failed'
            && $data['title'] === 'Ha fallado la copia de seguridad de la noche'
            && $data['body'] === 'Falló el 05/10/2026 03:40. La última copia correcta es del 04/10/2026 03:41. Revisa el servidor (DEPLOY.md, copias de seguridad).'
            && $data['url'] === null;
    });
});

test('avisa si la última copia correcta tiene más de 36 horas', function () {
    ($this->status)(['last_backup_at' => '2026-10-03T18:59:00Z', 'last_backup_ok' => true]);

    $this->artisan('app:check-storage')->assertSuccessful();

    Notification::assertSentTo($this->admin, BackupWarningNotification::class, fn (BackupWarningNotification $notification): bool => $notification->reason === 'backup_stale'
        && $notification->details === ['last_ok_at' => '03/10/2026 20:59', 'hours' => 36]);

    // 35 horas: todavía no.
    Notification::fake();
    $this->travelTo('2026-10-06 07:00:00');
    ($this->status)(['last_backup_at' => '2026-10-05T20:00:00Z', 'last_backup_ok' => true]);

    $this->artisan('app:check-storage')->assertSuccessful();

    Notification::assertNothingSent();
});

test('avisa si falló la prueba de restauración o la copia externa, cada motivo por separado y una vez al día', function () {
    ($this->status)([
        'last_backup_at' => '2026-10-05T01:41:02Z',
        'last_backup_ok' => true,
        'last_restore_check_at' => '2026-10-01T03:00:00Z',
        'last_restore_check_ok' => false,
        'last_offsite_at' => '2026-10-03T02:00:00Z',
        'last_offsite_ok' => false,
        'last_offsite_failed_at' => '2026-10-05T02:10:00Z',
    ]);

    $this->artisan('app:check-storage')->assertSuccessful();
    $this->artisan('app:check-storage')->assertSuccessful();

    Notification::assertSentToTimes($this->admin, BackupWarningNotification::class, 2);
    Notification::assertSentTo($this->admin, BackupWarningNotification::class, fn (BackupWarningNotification $notification, array $channels, User $notifiable): bool => $notification->reason === 'restore_failed'
        && $notification->toArray($notifiable)['body'] === 'La prueba del 01/10/2026 05:00 no ha podido restaurar la última copia. Revísala cuanto antes: una copia que no se restaura no sirve.');
    Notification::assertSentTo($this->admin, BackupWarningNotification::class, fn (BackupWarningNotification $notification): bool => $notification->reason === 'offsite_failed'
        && $notification->details === ['failed_at' => '05/10/2026 04:10', 'last_ok_at' => '03/10/2026 04:00']);
});

test('sin la copia externa activada (sin sus claves) no avisa de ella', function () {
    ($this->status)(['last_backup_at' => '2026-10-05T01:41:02Z', 'last_backup_ok' => true, 'last_restore_check_ok' => true]);

    $this->artisan('app:check-storage')->assertSuccessful();

    Notification::assertNothingSent();
});

test('un fichero de estado corrupto o con valores raros no avisa de las copias, pero queda en el log', function (string $contents) {
    Log::spy();
    ($this->status)($contents);

    $this->artisan('app:check-storage')->assertSuccessful();

    Notification::assertNotSentTo($this->admin, BackupWarningNotification::class);
    Log::shouldHaveReceived('warning')->atLeast()->once();
})->with([
    'no es JSON' => ['{"last_backup_ok": fals'],
    'una lista' => ['[true, false]'],
    'booleano como texto' => ['{"last_backup_ok": "false", "last_backup_at": "2026-10-05T01:41:02Z"}'],
    'fecha ilegible' => ['{"last_backup_ok": true, "last_backup_at": "ayer por la noche"}'],
]);

test('los avisos de sistema son obligatorios para el admin: llegan aunque intente quitarlos', function () {
    $this->admin->forceFill(['notification_preferences' => ['events' => ['system.disk_space' => ['app' => false, 'email' => false]]]])->save();
    $event = app(NotificationCatalog::class)->find('system.disk_space');

    expect($event?->mandatory)->toBeTrue()
        ->and(app(NotificationPreferences::class)->channelsFor($this->admin, 'system.disk_space'))->toBe(['database', 'mail'])
        ->and(app(NotificationPreferences::class)->channelsFor($this->admin, 'system.backup_failed'))->toBe(['database', 'mail']);
});

test('la medición real del disco devuelve el tamaño y el espacio libre', function () {
    $space = (new NativeDiskUsage)->measure(storage_path());

    expect($space)->not->toBeNull()
        ->and($space?->totalBytes)->toBeGreaterThan(0)
        ->and($space?->usedPercent())->toBeGreaterThanOrEqual(0.0)
        ->and($space?->usedPercent())->toBeLessThanOrEqual(100.0)
        ->and((new NativeDiskUsage)->measure('/ruta/que/no/existe'))->toBeNull();
});

test('se programa cada día a las 09:00 de Madrid, sin solaparse', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'app:check-storage'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 9 * * *')
        ->and($event->timezone)->toBe('Europe/Madrid')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->filtersPass(app()))->toBeTrue();
});
