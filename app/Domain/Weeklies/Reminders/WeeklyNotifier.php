<?php

namespace App\Domain\Weeklies\Reminders;

use App\Domain\Notifications\NotificationCatalog;
use App\Domain\Notifications\NotificationPreferences;
use App\Enums\WeeklyReminderChannel;
use App\Enums\WeeklyReminderStatus;
use App\Enums\WeeklyReminderTemplate;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklyReminderLog;
use App\Notifications\AppNotification;
use Carbon\CarbonImmutable;
use Closure;
use InvalidArgumentException;

/**
 * Envía un aviso de la weekly a varias personas por unos canales, con registro y deduplicación
 * (F-107 y F-108, D-201). Lo usan el comando weeklies:remind (reglas), el envío manual y «Recordar»,
 * «weekly cerrada» y el plazo cambiado; el recordatorio de los viernes reclama sus filas con claim().
 *
 * Para cada persona y canal lógico (app, email o push):
 * 1. Se decide si sale: la persona tiene que quererlo en /ajustes/notificaciones (D-073), Web Push
 *    tiene que estar configurado y la persona, tener algún navegador suscrito. Si no, la fila queda
 *    «omitida» con el motivo. Con el resumen diario activo, el email va a la campana y al resumen.
 * 2. Se RECLAMA la fila (trigger_key, canal, persona) de weekly_reminder_logs, cuyo índice único
 *    es la deduplicación: un mismo disparo no llega dos veces aunque el comando se repita, se
 *    solapen dos pasadas o se pulse dos veces.
 * 3. Una notificación por persona con los canales reclamados (AppNotification::$onlyChannels) y las
 *    filas que tiene que cerrar: «en cola» hasta que el canal la entrega (enviada) o falla (con el
 *    error, MarkWeeklyReminderFailed).
 *
 * Consultas acotadas sea cual sea el número de personas: suscripciones, filas ya reclamadas,
 * inserción y lectura de las nuevas.
 */
final class WeeklyNotifier
{
    public function __construct(
        private readonly NotificationCatalog $catalog,
        private readonly NotificationPreferences $preferences,
    ) {}

    /**
     * Con $byPreferences, los canales son «los que quiera cada persona» (weekly cerrada, plazo y
     * «Recordar»): solo se registra como omitido quien no recibe ninguno, no cada canal que no
     * quiere. Sin él (reglas y envío manual), cada canal elegido que no sale queda registrado.
     *
     * @param  iterable<User>  $users
     * @param  list<WeeklyReminderChannel>  $channels
     * @param  Closure(User): AppNotification  $make  la notificación de cada persona
     */
    public function send(
        string $kind,
        ?WeeklyCycle $cycle,
        WeeklyReminderTemplate $template,
        string $triggerKey,
        iterable $users,
        array $channels,
        Closure $make,
        ?User $sender = null,
        bool $byPreferences = false,
    ): WeeklyNoticeResult {
        $event = $this->catalog->find($kind) ?? throw new InvalidArgumentException("El aviso {$kind} no está en el catálogo.");
        $people = [];

        foreach ($users as $user) {
            $people[$user->id] = $user;
        }

        if ($people === [] || $channels === []) {
            return new WeeklyNoticeResult(0, 0, 0);
        }

        $subscribed = in_array(WeeklyReminderChannel::Push, $channels, true) && $this->preferences->pushAvailable()
            ? array_flip(PushSubscription::query()->whereIn('user_id', array_keys($people))->distinct()->pluck('user_id')->map(fn ($id): int => (int) $id)->all())
            : [];

        $rows = [];

        foreach ($people as $user) {
            $own = [];

            foreach ($channels as $channel) {
                [$laravel, $reason] = $this->resolve($user, $event->kind, $channel, isset($subscribed[$user->id]));
                $own[] = [
                    'user' => $user,
                    'channel' => $channel,
                    'laravel' => $laravel,
                    'status' => $laravel === null ? WeeklyReminderStatus::Skipped : WeeklyReminderStatus::Queued,
                    'error' => $reason,
                ];
            }

            if ($byPreferences) {
                $queued = array_values(array_filter($own, fn (array $row): bool => $row['laravel'] !== null));
                $own = $queued !== [] ? $queued : [$own[0]];
            }

            array_push($rows, ...$own);
        }

        $claimed = $this->claim($cycle, $template, $triggerKey, $rows, $sender);
        $notified = 0;
        $skipped = 0;
        $duplicates = 0;

        foreach ($people as $user) {
            $logIds = [];

            foreach ($rows as $row) {
                if ($row['user']->id !== $user->id) {
                    continue;
                }

                $logId = $claimed[$user->id][$row['channel']->value] ?? null;

                if ($logId === null) {
                    $duplicates++;
                } elseif ($row['laravel'] === null) {
                    $skipped++;
                } else {
                    $logIds[$row['laravel']][] = $logId;
                }
            }

            if ($logIds === []) {
                continue;
            }

            $notification = $make($user);
            $notification->onlyChannels = array_keys($logIds);

            if (property_exists($notification, 'reminderLogIds')) {
                $notification->reminderLogIds = $logIds;
            }

            $user->notify($notification);
            $notified++;
        }

        return new WeeklyNoticeResult($notified, $skipped, $duplicates);
    }

    /**
     * Reclama las filas que aún no existen para ese disparo (las que ya están no se tocan) y
     * devuelve, de las nuevas, persona → canal → id.
     *
     * @param  list<array{user: User, channel: WeeklyReminderChannel, status: WeeklyReminderStatus, error: string|null}>  $rows
     * @return array<int, array<string, int>>
     */
    public function claim(?WeeklyCycle $cycle, WeeklyReminderTemplate $template, string $triggerKey, array $rows, ?User $sender = null): array
    {
        if ($rows === []) {
            return [];
        }

        $userIds = array_values(array_unique(array_map(fn (array $row): int => $row['user']->id, $rows)));
        $channelValues = array_values(array_unique(array_map(fn (array $row): string => $row['channel']->value, $rows)));
        $existing = $this->keys($triggerKey, $userIds, $channelValues);
        $now = CarbonImmutable::now();
        $insert = [];
        $wanted = [];

        foreach ($rows as $row) {
            $key = "{$row['user']->id}:{$row['channel']->value}";

            if (isset($existing[$key]) || isset($wanted[$key])) {
                continue;
            }

            $wanted[$key] = true;
            $insert[] = [
                'weekly_cycle_id' => $cycle?->id,
                'user_id' => $row['user']->id,
                'recipient_name' => $row['user']->name,
                'recipient_email' => $row['user']->email,
                'template' => $template->value,
                'channel' => $row['channel']->value,
                'trigger_key' => $triggerKey,
                'status' => $row['status']->value,
                'error' => $row['error'],
                'sent_by' => $sender?->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($insert === []) {
            return [];
        }

        foreach (array_chunk($insert, 500) as $chunk) {
            WeeklyReminderLog::query()->insertOrIgnore($chunk);
        }

        $claimed = [];

        foreach ($this->keys($triggerKey, $userIds, $channelValues) as $key => $id) {
            if (isset($wanted[$key])) {
                [$userId, $channel] = explode(':', $key, 2);
                $claimed[(int) $userId][$channel] = $id;
            }
        }

        return $claimed;
    }

    /**
     * Canal de Laravel por el que sale un canal lógico para esta persona, o null con el motivo.
     *
     * @return array{0: string|null, 1: string|null}
     */
    public function resolve(User $user, string $kind, WeeklyReminderChannel $channel, bool $hasPushSubscription = true): array
    {
        $event = $this->catalog->find($kind);

        if ($event === null || ! $event->offers($channel->value)) {
            return [null, self::reason('channel')];
        }

        if ($channel === WeeklyReminderChannel::Push && ! $this->preferences->pushAvailable()) {
            return [null, self::reason('push_unavailable')];
        }

        if (! $this->preferences->wants($user, $event, $channel->value)) {
            return [null, self::reason('preferences')];
        }

        return match ($channel) {
            WeeklyReminderChannel::App => ['database', null],
            WeeklyReminderChannel::Email => $user->email === ''
                ? [null, self::reason('no_email')]
                : [! $event->mandatory && $this->preferences->dailyDigest($user) ? 'database' : 'mail', null],
            WeeklyReminderChannel::Push => $hasPushSubscription
                ? [(string) config('notifications.channels.push'), null]
                : [null, self::reason('push_unsubscribed')],
        };
    }

    /** Canal lógico de un canal de Laravel (para registrar lo que decide NotificationPreferences). */
    public static function logicalChannel(string $laravel): ?WeeklyReminderChannel
    {
        return match (true) {
            $laravel === 'database' => WeeklyReminderChannel::App,
            $laravel === 'mail' => WeeklyReminderChannel::Email,
            $laravel === (string) config('notifications.channels.push') => WeeklyReminderChannel::Push,
            default => null,
        };
    }

    /**
     * Filas de ese disparo: «persona:canal» → id.
     *
     * @param  list<int>  $userIds
     * @param  list<string>  $channels
     * @return array<string, int>
     */
    private function keys(string $triggerKey, array $userIds, array $channels): array
    {
        return WeeklyReminderLog::query()
            ->where('trigger_key', $triggerKey)
            ->whereIn('channel', $channels)
            ->whereIn('user_id', $userIds)
            ->get(['id', 'user_id', 'channel'])
            ->mapWithKeys(fn (WeeklyReminderLog $log): array => ["{$log->user_id}:{$log->channel->value}" => $log->id])
            ->all();
    }

    private static function reason(string $key): string
    {
        $text = __("weeklies.reminders.skipped.{$key}");

        return is_string($text) ? $text : $key;
    }
}
