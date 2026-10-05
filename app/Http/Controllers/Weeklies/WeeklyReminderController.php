<?php

namespace App\Http\Controllers\Weeklies;

use App\Domain\Notifications\NotificationPreferences;
use App\Domain\Weeklies\Reminders\WeeklyReminderRecipients;
use App\Domain\Weeklies\Reminders\WeeklyReminders;
use App\Domain\Weeklies\Reminders\WeeklyTemplates;
use App\Enums\WeeklyReminderChannel;
use App\Enums\WeeklyReminderStatus;
use App\Enums\WeeklyReminderTemplate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Weeklies\SendWeeklyRemindersRequest;
use App\Http\Requests\Weeklies\UpdateWeeklyRemindersRequest;
use App\Http\Resources\Projects\Paginated;
use App\Http\Resources\UserSummaryResource;
use App\Models\Setting;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklyReminderLog;
use App\Models\WeeklyReminderRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Avisos de la Weekly» (F-037 y F-101 a F-110, D-199 a D-201), el NotificationSettingsView de
 * WeeklySync como cuarta pestaña de /weeklies para quien gestiona la Weekly:
 * - las reglas de recordatorio (día, hora de Madrid y canal) y la weekly en el recordatorio de los
 *   viernes,
 * - las plantillas editables con vista previa y «Restaurar»,
 * - el envío manual a las personas pendientes (todas o las elegidas) y el registro de envíos,
 * - «Recordar» a una persona pendiente desde el resumen de /weeklies y desde Equipo.
 */
class WeeklyReminderController extends Controller
{
    public const int LOGS_PER_PAGE = 50;

    /** /weeklies/avisos?plantilla=&estado=&pagina=. */
    public function edit(Request $request, WeeklyTemplates $templates, WeeklyReminderRecipients $recipients, NotificationPreferences $preferences): Response
    {
        Gate::authorize('manage-weeklies');

        $cycle = WeeklyCycle::query()->active()->first();
        $filters = [
            'template' => in_array($request->query('plantilla'), WeeklyReminderTemplate::values(), true) ? (string) $request->query('plantilla') : null,
            'status' => in_array($request->query('estado'), WeeklyReminderStatus::values(), true) ? (string) $request->query('estado') : null,
        ];

        $logs = WeeklyReminderLog::query()
            ->with(['cycle:id,number', 'sender:id,name'])
            ->when($filters['template'] !== null, fn ($query) => $query->where('template', $filters['template']))
            ->when($filters['status'] !== null, fn ($query) => $query->where('status', $filters['status']))
            ->orderByDesc('id')
            ->paginate(self::LOGS_PER_PAGE, pageName: 'pagina')
            ->withQueryString();

        /** @var list<WeeklyReminderLog> $items */
        $items = $logs->items();

        return Inertia::render('weeklies/reminders', [
            'cycle' => $cycle === null ? null : [
                'id' => $cycle->id,
                'number' => $cycle->number,
                'label' => $cycle->label,
                'deadline_date' => $cycle->deadline_date->toDateString(),
            ],
            'rules' => WeeklyReminderRule::query()->orderBy('position')->orderBy('id')->get()
                ->map(fn (WeeklyReminderRule $rule): array => self::rule($rule))->values()->all(),
            'templates' => $templates->all(),
            'defaults' => $templates->defaults(),
            'variables' => WeeklyTemplates::VARIABLES,
            'friday' => [
                'weekly' => (bool) Setting::get('weekly_friday_reminder', true),
                'hours' => (bool) Setting::get('week_reminder_enabled', true),
            ],
            'pending' => $cycle === null ? [] : $recipients->pending($cycle)
                ->map(fn (User $user): array => (new UserSummaryResource($user))->resolve())->values()->all(),
            'logs' => Paginated::props($logs, array_map(fn (WeeklyReminderLog $log): array => self::log($log), $items)),
            'filters' => $filters,
            'push_available' => $preferences->pushAvailable(),
            'can' => [
                'send' => $cycle !== null && Gate::allows('remind', $cycle),
            ],
        ]);
    }

    /** Guarda las reglas, las plantillas y la weekly en el recordatorio de los viernes. */
    public function update(UpdateWeeklyRemindersRequest $request, WeeklyTemplates $templates): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        DB::transaction(function () use ($request, $templates, $user): void {
            $this->syncRules($request->reminderRules());
            $templates->save($request->templates(), $user);

            if ($request->has('friday_reminder')) {
                $value = $request->boolean('friday_reminder');

                if ($value !== (bool) Setting::get('weekly_friday_reminder', true)) {
                    Setting::set('weekly_friday_reminder', $value);

                    activity('weekly-reminders')
                        ->causedBy($user)
                        ->event('friday_updated')
                        ->withProperties(['weekly_friday_reminder' => $value])
                        ->log('friday_updated');
                }
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('weeklies.reminders.saved')]);

        return back();
    }

    /** Envío manual (F-109): a todas las pendientes o a las elegidas. */
    public function send(SendWeeklyRemindersRequest $request, WeeklyReminders $reminders): RedirectResponse
    {
        $cycle = WeeklyCycle::query()->active()->first();

        if ($cycle === null) {
            throw ValidationException::withMessages(['recipients' => __('weeklies.reminders.no_active')]);
        }

        Gate::authorize('remind', $cycle);

        /** @var User $user */
        $user = $request->user();
        $result = $reminders->sendManual($cycle, $request->userIds(), $request->template(), $request->channels(), $user);

        activity('weekly-reminders')
            ->causedBy($user)
            ->performedOn($cycle)
            ->event('reminders_sent')
            ->withProperties([
                'template' => $request->template()->value,
                'channels' => array_map(fn ($channel): string => $channel->value, $request->channels()),
                'recipients' => $request->userIds() ?? 'pending',
                'notified' => $result->notified,
                'skipped' => $result->skipped,
            ])
            ->log('reminders_sent');

        Inertia::flash('toast', [
            'type' => $result->notified > 0 ? 'success' : 'info',
            'message' => trans_choice('weeklies.reminders.sent', $result->notified, ['count' => $result->notified]),
        ]);

        return back();
    }

    /** «Recordar» a una persona pendiente de la semana activa (F-037 y F-110). */
    public function remind(Request $request, WeeklyCycle $cycle, WeeklyReminders $reminders): RedirectResponse
    {
        Gate::authorize('remind', $cycle);

        $validated = $request->validate(['user_id' => ['required', 'integer']]);
        $person = User::query()->find((int) $validated['user_id']);

        if ($person === null) {
            throw ValidationException::withMessages(['user_id' => __('weeklies.reminders.validation.users')]);
        }

        /** @var User $user */
        $user = $request->user();
        $result = $reminders->remindOne($cycle, $person, $user);

        [$type, $message] = match (true) {
            $result === null => ['info', __('weeklies.reminders.not_needed', ['name' => $person->name])],
            $result->notified > 0 => ['success', __('weeklies.reminders.reminded', ['name' => $person->name])],
            $result->duplicates > 0 => ['info', __('weeklies.reminders.duplicate')],
            default => ['info', __('weeklies.reminders.no_channel', ['name' => $person->name])],
        };

        if ($result !== null && $result->notified > 0) {
            activity('weekly-reminders')
                ->causedBy($user)
                ->performedOn($cycle)
                ->event('reminders_sent')
                ->withProperties(['template' => WeeklyReminderTemplate::Manual->value, 'recipients' => [$person->id], 'notified' => 1])
                ->log('reminders_sent');
        }

        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }

    /**
     * La lista entera de reglas: actualiza las que existen, crea las nuevas y borra las que faltan
     * (cada cambio queda en la auditoría, LogsDomainActivity). El orden es el de la lista.
     *
     * @param  list<array{id: int|null, channel: WeeklyReminderChannel, day_of_week: int, time: string, enabled: bool}>  $rows
     */
    private function syncRules(array $rows): void
    {
        $existing = WeeklyReminderRule::query()->get()->keyBy('id');
        $kept = [];

        foreach ($rows as $position => $row) {
            $rule = $row['id'] !== null ? $existing->get($row['id']) : null;
            $rule ??= new WeeklyReminderRule;
            $rule->fill([
                'channel' => $row['channel'],
                'day_of_week' => $row['day_of_week'],
                'time' => $row['time'],
                'enabled' => $row['enabled'],
                'position' => $position,
            ])->save();
            $kept[] = $rule->id;
        }

        foreach ($existing as $id => $rule) {
            if (! in_array($id, $kept, true)) {
                $rule->delete();
            }
        }
    }

    /**
     * @return array{id: int, channel: string, day_of_week: int, time: string, enabled: bool, position: int}
     */
    private static function rule(WeeklyReminderRule $rule): array
    {
        return [
            'id' => $rule->id,
            'channel' => $rule->channel->value,
            'day_of_week' => $rule->day_of_week,
            'time' => $rule->time,
            'enabled' => $rule->enabled,
            'position' => $rule->position,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function log(WeeklyReminderLog $log): array
    {
        return [
            'id' => $log->id,
            'weekly_cycle_id' => $log->weekly_cycle_id,
            'cycle_number' => $log->cycle?->number,
            'user_id' => $log->user_id,
            'recipient_name' => $log->recipient_name,
            'recipient_email' => $log->recipient_email,
            'template' => $log->template->value,
            'channel' => $log->channel->value,
            'status' => $log->status->value,
            'error' => $log->error,
            'sent_by_name' => $log->sender?->name,
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }
}
