<?php

use App\Domain\Notifications\NotificationCatalog;
use App\Notifications\AppNotification;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/*
| Contrato de la Fase 7 (D-073): cada AppNotification tiene su evento en el catálogo, y cada evento
| tiene sus textos y unos canales coherentes.
*/

test('toda AppNotification concreta tiene su evento en el catálogo', function () {
    $catalog = app(NotificationCatalog::class);
    $checked = 0;

    foreach (Finder::create()->files()->in(app_path('Notifications'))->name('*.php') as $file) {
        $class = 'App\\Notifications\\'.Str::of($file->getRelativePathname())->replace(['/', '.php'], ['\\', '']);

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || ! $reflection->isSubclassOf(AppNotification::class)) {
            continue;
        }

        /** @var AppNotification $notification */
        $notification = $reflection->newInstanceWithoutConstructor();

        expect($catalog->find($notification->kind()))->not->toBeNull("{$class} ({$notification->kind()}) no está en el catálogo");
        $checked++;
    }

    expect($checked)->toBeGreaterThan(15);
});

test('cada evento tiene grupo, textos y canales coherentes', function () {
    foreach (app(NotificationCatalog::class)->all() as $kind => $event) {
        expect($event->kind)->toBe($kind)
            ->and(NotificationCatalog::GROUPS)->toContain($event->group)
            ->and($event->channels)->not->toBeEmpty()
            ->and(array_diff($event->channels, NotificationCatalog::CHANNELS))->toBe([])
            ->and(array_diff($event->defaults, $event->channels))->toBe([])
            ->and(__("notifications.events.{$kind}.label"))->not->toBe("notifications.events.{$kind}.label")
            ->and(__("notifications.events.{$kind}.description"))->not->toBe("notifications.events.{$kind}.description")
            ->and(__("notifications.groups.{$event->group}"))->not->toBe("notifications.groups.{$event->group}");

        if ($event->mandatory) {
            expect($event->audience)->toBe(NotificationCatalog::AUDIENCE_ADMINS);
        }
    }
});
