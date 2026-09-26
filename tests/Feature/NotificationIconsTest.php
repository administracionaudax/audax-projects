<?php

use App\Notifications\AppNotification;
use Illuminate\Support\Facades\File;

/*
| INT-06: cada notificación manda en icon() un nombre que la campana sabe pintar (lista cerrada de
| resources/js/components/notifications/notification-icon.tsx). Si no, sale una campana genérica
| y todos los avisos se ven iguales.
*/

test('cada AppNotification::icon() está en la lista de iconos de la campana', function () {
    $source = File::get(resource_path('js/components/notifications/notification-icon.tsx'));
    $block = str($source)->after('const ICONS: Record<string, LucideIcon> = {')->before('};')->toString();
    preg_match_all("/^\\s*'?([a-z0-9-]+)'?\\s*:/m", $block, $matches);
    $allowed = $matches[1];
    expect($allowed)->toContain('bell');

    $icons = [];
    foreach (File::allFiles(app_path('Notifications')) as $file) {
        $class = 'App\\Notifications\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || ! $reflection->isSubclassOf(AppNotification::class)) {
            continue;
        }

        /** @var AppNotification $notification */
        $notification = $reflection->newInstanceWithoutConstructor();
        $icons[$class] = $notification->icon();
    }

    expect($icons)->not->toBeEmpty();

    foreach ($icons as $class => $icon) {
        expect($icon === null || in_array($icon, $allowed, true))
            ->toBeTrue("{$class}::icon() devuelve «{$icon}», que la campana no conoce");
    }
});
