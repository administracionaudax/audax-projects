<?php

namespace App\Domain\Privacy\Export\Sections;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Notificaciones de la campana: las que siguen guardadas (las leídas se borran pasado su plazo de
 * conservación, D-075).
 */
final class NotificationsSection extends Section
{
    public const int CHUNK = 500;

    public function key(): string
    {
        return 'notificaciones';
    }

    protected function textKey(): string
    {
        return 'notifications';
    }

    protected function columnKeys(): array
    {
        return ['created_at', 'read_at', 'kind', 'title', 'body', 'url'];
    }

    public function rows(User $user): iterable
    {
        $notifications = DatabaseNotification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->lazy(self::CHUNK);

        foreach ($notifications as $notification) {
            $data = $notification->data;

            yield [
                'created_at' => self::instant($notification->created_at),
                'read_at' => self::instant($notification->read_at),
                'kind' => self::string($data['kind'] ?? null),
                'title' => self::string($data['title'] ?? null),
                'body' => self::string($data['body'] ?? null),
                'url' => self::string($data['url'] ?? null),
            ];
        }
    }

    private static function string(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
