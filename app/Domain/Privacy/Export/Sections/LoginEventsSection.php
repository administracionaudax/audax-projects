<?php

namespace App\Domain\Privacy\Export\Sections;

use App\Models\LoginEvent;
use App\Models\User;

/**
 * Registros de acceso: los inicios de sesión correctos y fallidos con la cuenta (fecha, dirección IP
 * y navegador), los que siguen dentro de su plazo de conservación (D-075), con el método
 * (`password` o `google`, D-165).
 */
final class LoginEventsSection extends Section
{
    public const int CHUNK = 500;

    public function key(): string
    {
        return 'accesos';
    }

    protected function textKey(): string
    {
        return 'login_events';
    }

    protected function columnKeys(): array
    {
        return ['created_at', 'succeeded', 'method', 'ip_address', 'user_agent'];
    }

    public function rows(User $user): iterable
    {
        $events = LoginEvent::query()
            ->where('user_id', $user->id)
            ->lazyById(self::CHUNK);

        foreach ($events as $event) {
            yield [
                'created_at' => self::instant($event->created_at),
                'succeeded' => $event->succeeded,
                'method' => $event->method,
                'ip_address' => $event->ip_address,
                'user_agent' => $event->user_agent,
            ];
        }
    }
}
