<?php

namespace App\Domain\Notifications;

use App\Http\Resources\NotificationResource;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Contenido del resumen diario por email (SPEC §13, D-073) que envía notifications:daily-digest.
 *
 * - Quién lo recibe: las personas internas y activas con el resumen activado
 *   (NotificationPreferences::dailyDigest).
 * - Qué lleva: sus avisos de la campana SIN LEER creados desde $since cuyo evento quiere por email
 *   y no es obligatorio (NotificationPreferences::digestKinds): son los que, con el resumen activo,
 *   se quedaron en la campana en lugar de llegar en un email suelto.
 * - Cómo: agrupados como en /ajustes/notificaciones (NotificationCatalog::GROUPS), del más reciente
 *   al más antiguo, con hasta $perGroup títulos y enlaces por grupo y el total de cada grupo.
 *
 * Consultas acotadas: una para las personas y otra para los avisos de todas ellas. El kind está
 * dentro de `data` (texto JSON), así que se filtra aquí y no en SQL: igual en SQLite y PostgreSQL.
 */
final class DailyDigest
{
    public function __construct(
        private readonly NotificationPreferences $preferences,
        private readonly NotificationCatalog $catalog,
    ) {}

    /**
     * @return Collection<int, User>
     */
    public function recipients(): Collection
    {
        return User::query()->active()->internal()->orderBy('id')->get()
            ->filter(fn (User $user): bool => $this->preferences->dailyDigest($user))
            ->values();
    }

    /**
     * Grupos del resumen de cada persona (solo las que tienen algo que contar).
     *
     * @param  Collection<int, User>  $users
     * @return array<int, list<array{group: string, total: int, items: list<array{title: string, url: string|null}>}>> id de la persona → grupos
     */
    public function groupsFor(Collection $users, CarbonInterface $since, int $perGroup): array
    {
        if ($users->isEmpty()) {
            return [];
        }

        $unread = DatabaseNotification::query()
            ->where('notifiable_type', (new User)->getMorphClass())
            ->whereIn('notifiable_id', $users->modelKeys())
            ->whereNull('read_at')
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'notifiable_id', 'data', 'created_at'])
            ->groupBy('notifiable_id');

        $digests = [];

        foreach ($users as $user) {
            /** @var iterable<DatabaseNotification> $notifications */
            $notifications = $unread->get($user->id) ?? [];
            $groups = $this->groups($notifications, $this->preferences->digestKinds($user), max($perGroup, 1));

            if ($groups !== []) {
                $digests[$user->id] = $groups;
            }
        }

        return $digests;
    }

    /**
     * @param  iterable<DatabaseNotification>  $notifications  del más reciente al más antiguo
     * @param  list<string>  $kinds  eventos que van al resumen
     * @return list<array{group: string, total: int, items: list<array{title: string, url: string|null}>}>
     */
    private function groups(iterable $notifications, array $kinds, int $perGroup): array
    {
        $byGroup = [];

        foreach ($notifications as $notification) {
            /** @var array<string, mixed> $data */
            $data = (array) $notification->data;
            $kind = is_string($data['kind'] ?? null) ? $data['kind'] : '';
            $event = in_array($kind, $kinds, true) ? $this->catalog->find($kind) : null;

            if ($event === null) {
                continue;
            }

            $group = $byGroup[$event->group] ?? ['total' => 0, 'items' => []];
            $group['total']++;

            if (count($group['items']) < $perGroup) {
                $title = is_string($data['title'] ?? null) && trim($data['title']) !== ''
                    ? $data['title']
                    : self::text("notifications.events.{$kind}.label");

                $group['items'][] = ['title' => $title, 'url' => NotificationResource::safeUrl($data['url'] ?? null)];
            }

            $byGroup[$event->group] = $group;
        }

        $ordered = [];

        foreach (NotificationCatalog::GROUPS as $key) {
            if (isset($byGroup[$key])) {
                $ordered[] = ['group' => $key, 'total' => $byGroup[$key]['total'], 'items' => $byGroup[$key]['items']];
            }
        }

        return $ordered;
    }

    private static function text(string $key): string
    {
        $line = __($key);

        return is_string($line) ? $line : $key;
    }
}
