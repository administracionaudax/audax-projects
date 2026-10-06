<?php

namespace App\Domain\Notifications;

use App\Domain\DayPlan\DayPlanAccess;
use App\Domain\Weeklies\AppModules;
use App\Enums\AppModule;
use App\Models\ProjectMember;
use App\Models\User;

/**
 * Qué recibe cada persona y por qué canal (SPEC §13, D-073). Es el ÚNICO sitio que decide los
 * canales de una AppNotification (AppNotification::via llama a channelsFor).
 *
 * Se guarda en users.notification_preferences SOLO lo que difiere del catálogo:
 *   {"events": {"task.assigned": {"email": true}}, "daily_digest": false}
 * Así, un valor por defecto nuevo del catálogo llega a todos los que no lo han tocado.
 *
 * Resumen diario: quien lo activa deja de recibir los emails sueltos de los eventos no
 * obligatorios. Esos avisos quedan en la campana (canal database) y salen en el resumen de las
 * 08:00 si siguen sin leer (comando notifications:daily-digest, D-073).
 */
final class NotificationPreferences
{
    public function __construct(private readonly NotificationCatalog $catalog) {}

    /**
     * Canales de Laravel para una notificación, en el orden del catálogo; null si no decide
     * (quien la recibe no es un usuario o el evento no está en el catálogo): entonces
     * AppNotification usa sus canales de reserva.
     *
     * @return list<string>|null
     */
    public function channelsFor(object $notifiable, string $kind): ?array
    {
        $event = $this->catalog->find($kind);

        if ($event === null || ! $notifiable instanceof User) {
            return null;
        }

        $digest = ! $event->mandatory && $this->dailyDigest($notifiable);
        $channels = [];

        foreach ($event->channels as $channel) {
            if (! $this->wants($notifiable, $event, $channel)) {
                continue;
            }

            if ($channel === NotificationCatalog::EMAIL && $digest) {
                $channels[] = 'database';

                continue;
            }

            $laravel = $this->laravelChannel($channel);

            if ($laravel !== null) {
                $channels[] = $laravel;
            }
        }

        return array_values(array_unique($channels));
    }

    /**
     * ¿Quiere $user el evento por $channel? Los obligatorios siempre llegan por sus canales por
     * defecto; Web Push solo cuenta si está configurado.
     */
    public function wants(User $user, NotificationEvent $event, string $channel): bool
    {
        if (! $event->offers($channel)) {
            return false;
        }

        if ($channel === NotificationCatalog::PUSH && ! $this->pushAvailable()) {
            return false;
        }

        if ($event->mandatory) {
            return $event->enabledByDefault($channel);
        }

        return $this->stored($user)['events'][$event->kind][$channel] ?? $event->enabledByDefault($channel);
    }

    public function dailyDigest(User $user): bool
    {
        return $this->stored($user)['daily_digest'];
    }

    public function pushAvailable(): bool
    {
        return $this->laravelChannel(NotificationCatalog::PUSH) !== null;
    }

    /**
     * Eventos que $user quiere por email y que, con el resumen diario activo, van al resumen.
     *
     * @return list<string>
     */
    public function digestKinds(User $user): array
    {
        $kinds = [];

        foreach ($this->catalog->all() as $event) {
            if (! $event->mandatory && $this->wants($user, $event, NotificationCatalog::EMAIL)) {
                $kinds[] = $event->kind;
            }
        }

        return $kinds;
    }

    /**
     * Preferencias de $user para /ajustes/notificaciones (resources/js/types/notification-settings.ts):
     * solo los eventos que se le ofrecen, por grupos y en el orden del catálogo.
     *
     * @return array{daily_digest: bool, push_available: bool, groups: list<array{key: string, label: string, events: list<array{kind: string, label: string, description: string, mandatory: bool, channels: array<string, array{offered: bool, enabled: bool}>}>}>}
     */
    public function forUser(User $user): array
    {
        $managesProjects = null;
        $groups = [];

        foreach ($this->catalog->all() as $event) {
            if (! $this->visibleTo($user, $event, $managesProjects)) {
                continue;
            }

            $channels = [];

            foreach (NotificationCatalog::CHANNELS as $channel) {
                $channels[$channel] = [
                    'offered' => $event->offers($channel) && ($channel !== NotificationCatalog::PUSH || $this->pushAvailable()),
                    'enabled' => $this->wants($user, $event, $channel),
                ];
            }

            $groups[$event->group][] = [
                'kind' => $event->kind,
                'label' => __("notifications.events.{$event->kind}.label"),
                'description' => __("notifications.events.{$event->kind}.description"),
                'mandatory' => $event->mandatory,
                'channels' => $channels,
            ];
        }

        $ordered = [];

        foreach (NotificationCatalog::GROUPS as $group) {
            if (isset($groups[$group])) {
                $ordered[] = ['key' => $group, 'label' => __("notifications.groups.{$group}"), 'events' => $groups[$group]];
            }
        }

        return [
            'daily_digest' => $this->dailyDigest($user),
            'push_available' => $this->pushAvailable(),
            'groups' => $ordered,
        ];
    }

    /**
     * Guarda las preferencias de $user. Ignora los eventos que no existen o no se le ofrecen, los
     * obligatorios y los canales que el evento no ofrece; guarda solo lo que difiere del catálogo.
     *
     * @param  array<string, array<string, bool>>  $events  kind → canal → activado
     */
    public function update(User $user, array $events, bool $dailyDigest): void
    {
        $managesProjects = null;
        $stored = $this->stored($user)['events'];

        foreach ($events as $kind => $channels) {
            $event = $this->catalog->find((string) $kind);

            if ($event === null || $event->mandatory || ! $this->visibleTo($user, $event, $managesProjects)) {
                continue;
            }

            foreach ($channels as $channel => $enabled) {
                if (! $event->offers((string) $channel)) {
                    continue;
                }

                if ((bool) $enabled === $event->enabledByDefault((string) $channel)) {
                    unset($stored[$event->kind][$channel]);
                } else {
                    $stored[$event->kind][$channel] = (bool) $enabled;
                }
            }

            if (($stored[$event->kind] ?? []) === []) {
                unset($stored[$event->kind]);
            }
        }

        $user->forceFill(['notification_preferences' => [
            'events' => $stored,
            'daily_digest' => $dailyDigest,
        ]])->save();
    }

    /**
     * ¿Se ofrece el evento a $user? $managesProjects memoriza la consulta de gestor de proyecto.
     */
    public function visibleTo(User $user, NotificationEvent $event, ?bool &$managesProjects = null): bool
    {
        if (! $user->isInternal()) {
            return false;
        }

        return match ($event->audience) {
            NotificationCatalog::AUDIENCE_ADMINS => $user->isAdmin(),
            NotificationCatalog::AUDIENCE_WEEKLIES => $user->writesWeeklies() && ! $user->isCollaborator() && AppModules::visibleTo($user, AppModule::Weeklies),
            NotificationCatalog::AUDIENCE_SUGGESTIONS => $user->writesWeeklies() && ! $user->isCollaborator()
                && AppModules::visibleTo($user, AppModule::Help) && AppModules::visibleTo($user, AppModule::Suggestions),
            NotificationCatalog::AUDIENCE_DAY_PLAN => DayPlanAccess::uses($user),
            NotificationCatalog::AUDIENCE_APPROVERS => $user->isAdmin() || $user->isDepartmentManager(),
            NotificationCatalog::AUDIENCE_MANAGERS => $user->isAdmin() || $user->isDepartmentManager()
                || ($managesProjects ??= ProjectMember::query()->where('user_id', $user->id)->where('is_manager', true)->exists()),
            default => true,
        };
    }

    private function laravelChannel(string $channel): ?string
    {
        return match ($channel) {
            NotificationCatalog::APP => 'database',
            NotificationCatalog::EMAIL => 'mail',
            NotificationCatalog::PUSH => ($push = config('notifications.channels.push')) !== null && $push !== '' ? (string) $push : null,
            default => null,
        };
    }

    /**
     * Lo guardado, saneado: cualquier valor raro se ignora.
     *
     * @return array{events: array<string, array<string, bool>>, daily_digest: bool}
     */
    private function stored(User $user): array
    {
        $raw = $user->notification_preferences;
        $events = [];

        if (is_array($raw) && is_array($raw['events'] ?? null)) {
            foreach ($raw['events'] as $kind => $channels) {
                if (! is_array($channels)) {
                    continue;
                }

                foreach ($channels as $channel => $enabled) {
                    if (is_string($channel) && in_array($channel, NotificationCatalog::CHANNELS, true) && is_bool($enabled)) {
                        $events[(string) $kind][$channel] = $enabled;
                    }
                }
            }
        }

        return [
            'events' => $events,
            'daily_digest' => is_array($raw) && ($raw['daily_digest'] ?? false) === true,
        ];
    }
}
