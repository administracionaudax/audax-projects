<?php

use App\Domain\Notifications\NotificationCatalog;
use App\Notifications\AppNotification;
use App\Notifications\Chat\ChatDirectMessageNotification;
use App\Notifications\Chat\ChatEveryoneNotification;
use App\Notifications\Chat\ChatMentionNotification;
use App\Notifications\Chat\ChatMessageNotification;
use App\Notifications\Chat\TranscriptionsFailing;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/*
| Contrato de la Fase 7 (D-073): cada AppNotification tiene su evento en el catálogo, y cada evento
| tiene sus textos y unos canales coherentes.
*/

/**
 * Clases concretas que extienden AppNotification en app/Notifications (incluidas las del chat).
 *
 * @return list<ReflectionClass<AppNotification>>
 */
function appNotificationClasses(): array
{
    $classes = [];

    foreach (Finder::create()->files()->in(app_path('Notifications'))->name('*.php') as $file) {
        $class = 'App\\Notifications\\'.Str::of($file->getRelativePathname())->replace(['/', '.php'], ['\\', '']);

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if (! $reflection->isAbstract() && $reflection->isSubclassOf(AppNotification::class)) {
            /** @var ReflectionClass<AppNotification> $reflection */
            $classes[] = $reflection;
        }
    }

    return $classes;
}

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

test('los avisos del chat de la Fase 6 son AppNotification con su evento en el catálogo', function () {
    $catalog = app(NotificationCatalog::class);
    $classes = array_map(fn (ReflectionClass $class): string => $class->getName(), appNotificationClasses());

    foreach ([
        ChatDirectMessageNotification::class => 'chat.direct',
        ChatMentionNotification::class => 'chat.mention',
        ChatEveryoneNotification::class => 'chat.mention',
        TranscriptionsFailing::class => 'system.transcriptions_failing',
    ] as $class => $kind) {
        /** @var AppNotification $notification */
        $notification = (new ReflectionClass($class))->newInstanceWithoutConstructor();

        expect($classes)->toContain($class)
            ->and($notification->kind())->toBe($kind)
            ->and($catalog->find($kind))->not->toBeNull();
    }

    expect($catalog->find('chat.direct')?->group)->toBe('chat')
        ->and($catalog->find('system.transcriptions_failing')?->mandatory)->toBeTrue();
});

test('ninguna AppNotification decide sus canales a mano (solo el chat quita el navegador)', function () {
    foreach (appNotificationClasses() as $class) {
        $declaring = $class->getMethod('via')->getDeclaringClass()->getName();

        expect($declaring)->toBeIn([AppNotification::class, ChatMessageNotification::class], "{$class->getName()} sobrescribe via()");
    }
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

        // Obligatorios: los de sistema para el admin y, desde la Fase 11 (D-356), los legales del
        // registro de jornada (el resumen del mes, su desconfirmación, el resumen semanal de horas
        // extra y el tope anual), para quien usa el módulo.
        if ($event->mandatory) {
            expect($event->audience)->toBeIn([NotificationCatalog::AUDIENCE_ADMINS, NotificationCatalog::AUDIENCE_PEOPLE]);
        }
    }
});
