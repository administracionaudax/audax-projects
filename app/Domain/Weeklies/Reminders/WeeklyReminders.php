<?php

namespace App\Domain\Weeklies\Reminders;

use App\Domain\Weeklies\AppModules;
use App\Enums\AppModule;
use App\Enums\WeeklyReminderChannel;
use App\Enums\WeeklyReminderTemplate;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklyReminderRule;
use App\Notifications\Weeklies\WeeklyClosedNotice;
use App\Notifications\Weeklies\WeeklyDeadlineChanged;
use App\Notifications\Weeklies\WeeklyReminder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Los avisos de la weekly (F-037, F-095 y F-101 a F-110, D-199 a D-201), cada uno con su clave de
 * disparo para la deduplicación:
 *
 * | Aviso                | A quién                   | Canales                         | Clave                                |
 * |----------------------|---------------------------|---------------------------------|--------------------------------------|
 * | Regla                | pendientes                | el de la regla                  | rule:{regla}:{semana}:{fecha}T{hora} |
 * | Envío manual         | pendientes elegidos       | los que elige quien envía       | manual:{semana}:{quien}:{minuto}     |
 * | «Recordar»           | una persona pendiente     | los que quiera (app, email, nav.)| remind:{semana}:{persona}:{minuto}   |
 * | Weekly cerrada       | todo el equipo activo     | app, email y navegador          | closed:{semana}                      |
 * | Plazo cambiado       | pendientes                | app, email y navegador          | deadline:{semana}:{fecha}            |
 *
 * En todos, cada persona decide en /ajustes/notificaciones qué canales quiere (por defecto: los
 * recordatorios en la app y por email; «weekly cerrada» igual; el plazo, en la app). El manual y
 * «Recordar» se deduplican por minuto: dos clics seguidos no mandan dos.
 */
final class WeeklyReminders
{
    public function __construct(
        private readonly WeeklyNotifier $notifier,
        private readonly WeeklyTemplates $templates,
        private readonly WeeklyReminderRecipients $recipients,
    ) {}

    /**
     * Las reglas que tocan ahora con la semana activa (comando weeklies:remind, cada 5 minutos).
     *
     * @return list<array{rule: WeeklyReminderRule, result: WeeklyNoticeResult}>
     */
    public function runDueRules(WeeklyCycle $cycle, ?CarbonImmutable $now = null): array
    {
        $rules = WeeklyReminderRule::query()->where('enabled', true)->orderBy('position')->orderBy('id')->get();
        $due = WeeklyReminderSchedule::due($rules, $now ?? CarbonImmutable::now());

        if ($due === []) {
            return [];
        }

        $pending = $this->recipients->pending($cycle);
        $results = [];

        foreach ($due as $item) {
            $results[] = [
                'rule' => $item['rule'],
                'result' => $this->remind(
                    $cycle,
                    $pending,
                    WeeklyReminderTemplate::Automatic,
                    [$item['rule']->channel],
                    WeeklyReminderSchedule::triggerKey($item['rule'], $cycle->id, $item['date']),
                ),
            ];
        }

        return $results;
    }

    /**
     * Envío manual (F-109) a las pendientes elegidas (o a todas las pendientes con $userIds null).
     *
     * @param  list<int>|null  $userIds
     * @param  list<WeeklyReminderChannel>  $channels
     */
    public function sendManual(WeeklyCycle $cycle, ?array $userIds, WeeklyReminderTemplate $template, array $channels, User $sender): WeeklyNoticeResult
    {
        $minute = CarbonImmutable::now()->utc()->format('YmdHi');

        return $this->remind(
            $cycle,
            $this->recipients->pending($cycle, $userIds),
            $template,
            $channels,
            "manual:{$cycle->id}:{$sender->id}:{$minute}",
            $sender,
        );
    }

    /**
     * «Recordar» a una persona pendiente (F-037 y F-110), con la plantilla manual. Null si no le toca
     * (ya ha enviado, está exenta o no participa).
     */
    public function remindOne(WeeklyCycle $cycle, User $user, User $sender): ?WeeklyNoticeResult
    {
        $pending = $this->recipients->pending($cycle, [$user->id]);

        if ($pending->isEmpty()) {
            return null;
        }

        $minute = CarbonImmutable::now()->utc()->format('YmdHi');

        return $this->remind(
            $cycle,
            $pending,
            WeeklyReminderTemplate::Manual,
            WeeklyReminderChannel::cases(),
            "remind:{$cycle->id}:{$user->id}:{$minute}",
            $sender,
            byPreferences: true,
        );
    }

    /** «Weekly cerrada» (F-095) a todo el equipo activo, una sola vez por semana. */
    public function notifyClosed(WeeklyCycle $cycle): WeeklyNoticeResult
    {
        if (! AppModules::enabled(AppModule::Weeklies)) {
            return new WeeklyNoticeResult(0, 0, 0);
        }

        $template = $this->templates->get(WeeklyReminderTemplate::WeeklyClosed);

        return $this->notifier->send(
            'weeklies.closed',
            $cycle,
            WeeklyReminderTemplate::WeeklyClosed,
            "closed:{$cycle->id}",
            $this->recipients->team(),
            WeeklyReminderChannel::cases(),
            fn (User $user): WeeklyClosedNotice => new WeeklyClosedNotice($cycle, $template['subject'], $template['body']),
            byPreferences: true,
        );
    }

    /** El plazo de la semana activa ha cambiado: aviso a quien aún debe enviarla, salvo a quien lo cambia (D-199). */
    public function notifyDeadline(WeeklyCycle $cycle, ?User $sender = null): WeeklyNoticeResult
    {
        if (! $cycle->isActive() || ! AppModules::enabled(AppModule::Weeklies)) {
            return new WeeklyNoticeResult(0, 0, 0);
        }

        return $this->notifier->send(
            'weeklies.deadline_changed',
            $cycle,
            WeeklyReminderTemplate::Deadline,
            "deadline:{$cycle->id}:{$cycle->deadline_date->toDateString()}",
            // Quien cambia el plazo ya lo sabe: no se avisa a sí mismo.
            $this->recipients->pending($cycle)->reject(fn (User $user): bool => $user->id === $sender?->id),
            WeeklyReminderChannel::cases(),
            fn (User $user): WeeklyDeadlineChanged => new WeeklyDeadlineChanged($cycle),
            $sender,
            byPreferences: true,
        );
    }

    /**
     * @param  Collection<int, User>  $users
     * @param  list<WeeklyReminderChannel>  $channels
     */
    private function remind(WeeklyCycle $cycle, Collection $users, WeeklyReminderTemplate $template, array $channels, string $triggerKey, ?User $sender = null, bool $byPreferences = false): WeeklyNoticeResult
    {
        $text = $this->templates->get($template);

        return $this->notifier->send(
            'weeklies.reminder',
            $cycle,
            $template,
            $triggerKey,
            $users,
            $channels,
            fn (User $user): WeeklyReminder => new WeeklyReminder($cycle, $template, $text['subject'], $text['body']),
            $sender,
            $byPreferences,
        );
    }
}
