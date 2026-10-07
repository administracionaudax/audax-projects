<?php

namespace App\Domain\People;

use App\Domain\Weeklies\AppModules;
use App\Enums\AppModule;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

/**
 * Avisos del registro de jornada (D-339 y D-356): solo salen con el módulo `people` encendido de
 * verdad (en modo de prueba, D-239, no sale ninguno) y nunca a una lista vacía.
 */
final class PeopleNotifier
{
    /**
     * @param  iterable<User>  $recipients
     */
    public static function send(iterable $recipients, object $notification): void
    {
        $list = [];

        foreach ($recipients as $recipient) {
            $list[$recipient->id] = $recipient;
        }

        if ($list === [] || ! AppModules::enabled(AppModule::People)) {
            return;
        }

        Notification::send(array_values($list), $notification);
    }
}
