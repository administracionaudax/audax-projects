<?php

namespace App\Domain\Import\WeeklySync\Stages;

use App\Domain\Import\WeeklySync\WeeklySyncContext;
use App\Domain\Import\WeeklySync\WeeklySyncImportReport as Report;
use App\Domain\Weeklies\Reminders\WeeklyTemplates;
use App\Enums\WeeklyReminderChannel;
use App\Enums\WeeklyReminderStatus;
use App\Enums\WeeklyReminderTemplate;
use App\Models\Setting;
use App\Models\WeeklyReminderLog;
use App\Models\WeeklyReminderRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Avisos (D-149 y D-217):
 *   - las reglas: `email_reminders` (canal email) y `web_notification_reminders` (canal push), con
 *     el día de 0 = domingo a ISO (7),
 *   - las plantillas de correo, solo las que el propietario cambió en WeeklySync (las que difieren
 *     de los textos de serie de WeeklySync) y solo si en Audax siguen con el texto de serie;
 *     «WeeklySync» pasa a «Audax Proyectos»,
 *   - el registro de envíos (`email_log` → `weekly_reminder_logs`, canal email), con una clave
 *     propia (`ws:<id>:<clave original>`) para no chocar con la deduplicación de Audax.
 */
final class RemindersStage
{
    /**
     * Textos de serie de WeeklySync (migraciones 011 y 023): si siguen así, no se importan.
     */
    public const array WEEKLYSYNC_DEFAULTS = [
        'automatic' => [
            'subject' => 'Recordatorio Automático: Reporte Semanal {semana}',
            'body' => "Hola {nombre},\n\nEste es un recordatorio automático para que completes tu reporte semanal de la semana {semana}.\n\nPor favor, entra a la plataforma WeeklySync y completa tu reporte antes del viernes.\n\nGracias,\nEquipo de WeeklySync",
        ],
        'manual' => [
            'subject' => 'Recordatorio: Reporte Semanal Pendiente',
            'body' => "Hola {nombre},\n\nTe recordamos que aún no has completado tu reporte semanal.\n\nPor favor, complétalo lo antes posible.\n\nGracias,\nEquipo de WeeklySync",
        ],
        'weekly_closed' => [
            'subject' => 'Weekly generada: {semana}',
            'body' => "Hola {nombre},\n\nLa weekly {semana} ya se ha generado y cerrado.\n\nYa puedes revisarla en WeeklySync desde el siguiente enlace:\n{weekly_url}\n\nGracias,\nEquipo de WeeklySync",
        ],
    ];

    public const int CHUNK = 200;

    public function __construct(private readonly WeeklyTemplates $templates) {}

    public function run(WeeklySyncContext $context): void
    {
        DB::transaction(function () use ($context): void {
            $this->rules($context);
            $this->templates($context);
        });

        $this->logs($context);
    }

    private function rules(WeeklySyncContext $context): void
    {
        $position = (int) WeeklyReminderRule::query()->max('position');

        foreach (['email_reminders' => WeeklyReminderChannel::Email, 'web_notification_reminders' => WeeklyReminderChannel::Push] as $table => $channel) {
            foreach ($context->rows($table) as $row) {
                $id = WeeklySyncContext::id($row['id'] ?? null);
                $day = is_numeric($row['day_of_week'] ?? null) ? (int) $row['day_of_week'] : -1;
                $time = WeeklySyncContext::str($row['time'] ?? '');

                if ($day < 0 || $day > 6 || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
                    $context->report->skip('reminder_rules', 'Reglas con el día o la hora mal');

                    continue;
                }

                $kind = $table === 'email_reminders' ? 'email_rule' : 'push_rule';
                $isoDay = $day === 0 ? 7 : $day;
                $local = $context->refs->find($kind, $id);
                $rule = $local !== null ? WeeklyReminderRule::query()->find($local) : null;

                if ($rule !== null && $context->refs->find($kind.'_created', $id) !== $rule->id) {
                    // Casó con una regla de Audax en una pasada anterior: no se toca.
                    $context->report->skip('reminder_rules', 'Reglas que ya estaban en Audax (mismo canal, día y hora)');

                    continue;
                }

                // Si en Audax ya hay una regla igual (canal, día y hora), se usa esa: dos reglas
                // iguales mandarían dos avisos.
                $same = $rule === null
                    ? WeeklyReminderRule::query()->where(['channel' => $channel->value, 'day_of_week' => $isoDay, 'time' => $time])->first()
                    : null;

                if ($same !== null) {
                    $context->refs->put($kind, $id, 'weekly_reminder_rule', $same->id);
                    $context->report->skip('reminder_rules', 'Reglas que ya estaban en Audax (mismo canal, día y hora)');

                    continue;
                }

                $created = $rule === null;
                $rule ??= new WeeklyReminderRule(['position' => ++$position]);

                $rule->fill([
                    'channel' => $channel,
                    'day_of_week' => $isoDay,
                    'time' => $time,
                    'enabled' => ($row['enabled'] ?? true) === true,
                ]);

                $outcome = $created ? Report::CREATED : ($rule->isDirty() ? Report::UPDATED : Report::UNCHANGED);
                if ($outcome !== Report::UNCHANGED) {
                    $rule->save();
                }

                $context->refs->put($kind, $id, 'weekly_reminder_rule', $rule->id);
                $context->refs->put($kind.'_created', $id, 'weekly_reminder_rule', $rule->id);
                $context->report->count('reminder_rules', $outcome);
            }
        }
    }

    private function templates(WeeklySyncContext $context): void
    {
        $rows = $context->rows('email_templates');
        $stored = Setting::get(WeeklyTemplates::SETTING);
        $stored = is_array($stored) ? $stored : [];
        $defaults = $this->templates->defaults();
        $changed = false;

        foreach ($rows as $row) {
            $key = WeeklySyncContext::str($row['id'] ?? '');
            $template = WeeklyReminderTemplate::tryFrom($key);

            if ($template === null || ! in_array($key, WeeklyReminderTemplate::EDITABLE, true)) {
                $context->report->skip('templates', 'Plantillas que no existen en Audax');

                continue;
            }

            $subject = WeeklyTemplates::normalize(WeeklySyncContext::str($row['subject'] ?? ''));
            $body = WeeklyTemplates::normalize((string) ($row['body'] ?? ''));
            $original = self::WEEKLYSYNC_DEFAULTS[$key];

            if ($subject === '' || $body === '' || ($subject === $original['subject'] && $body === WeeklyTemplates::normalize($original['body']))) {
                $context->report->skip('templates', 'Plantillas con el texto de serie de WeeklySync');

                continue;
            }

            $value = [
                'subject' => Str::limit(str_replace('WeeklySync', 'Audax Proyectos', $subject), WeeklyTemplates::SUBJECT_MAX, ''),
                'body' => Str::limit(str_replace('WeeklySync', 'Audax Proyectos', $body), WeeklyTemplates::BODY_MAX, ''),
            ];

            $current = $stored[$key] ?? null;

            if ($current === $value) {
                $context->report->count('templates', Report::UNCHANGED);

                continue;
            }

            if (is_array($current) && $current !== ($defaults[$key] ?? null)) {
                $context->report->skip('templates', 'Plantillas ya cambiadas en Audax');

                continue;
            }

            $stored[$key] = $value;
            $changed = true;
            $context->report->count('templates', $current === null ? Report::CREATED : Report::UPDATED);
        }

        if ($changed) {
            Setting::set(WeeklyTemplates::SETTING, $stored);
        }
    }

    private function logs(WeeklySyncContext $context): void
    {
        $rows = $context->rows('email_log');
        $weeksByNumber = [];

        foreach ($context->dump->rows('week_cycles') as $week) {
            $number = WeeklySyncContext::str($week['number'] ?? '');
            if ($number !== '' && ($cycle = $context->cycle($week['id'] ?? null)) !== null) {
                $weeksByNumber[$number] = $cycle;
            }
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::transaction(function () use ($context, $chunk, $weeksByNumber): void {
                foreach ($chunk as $row) {
                    $this->log($context, $row, $weeksByNumber);
                }
            });
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $weeksByNumber
     */
    private function log(WeeklySyncContext $context, array $row, array $weeksByNumber): void
    {
        $id = WeeklySyncContext::id($row['id'] ?? null);
        $template = WeeklyReminderTemplate::tryFrom(WeeklySyncContext::str($row['template_id'] ?? ''));
        $status = WeeklyReminderStatus::tryFrom(strtolower(WeeklySyncContext::str($row['status'] ?? '')));

        if ($template === null || $status === null) {
            $context->report->skip('reminder_logs', 'Envíos con una plantilla o un estado desconocidos');

            return;
        }

        $email = Str::lower(WeeklySyncContext::str($row['recipient_email'] ?? ''));
        $cycle = $context->cycle($row['week_id'] ?? null) ?? $weeksByNumber[WeeklySyncContext::str($row['week_number'] ?? '')] ?? null;
        $original = WeeklySyncContext::str($row['trigger_key'] ?? '');

        $local = $context->refs->find('email_log', $id);
        $log = $local !== null ? WeeklyReminderLog::query()->find($local) : null;
        $created = $log === null;
        $log ??= new WeeklyReminderLog;

        $log->fill([
            'weekly_cycle_id' => $cycle,
            'user_id' => $context->usersByEmail[$email] ?? null,
            'recipient_name' => Str::limit(WeeklySyncContext::str($row['recipient_name'] ?? ''), 255, ''),
            'recipient_email' => $email !== '' ? Str::limit($email, 255, '') : null,
            'template' => $template,
            'channel' => WeeklyReminderChannel::Email,
            'trigger_key' => Str::limit('ws:'.$id.($original !== '' ? ':'.$original : ''), 120, ''),
            'status' => $status,
            'error' => WeeklySyncContext::nullableStr($row['error_message'] ?? null),
            'sent_by' => null,
        ]);

        if ($created && ($sentAt = WeeklySyncContext::instant($row['sent_at'] ?? null)) !== null) {
            $log->created_at = $sentAt;
            $log->updated_at = $sentAt;
        }

        $outcome = $created ? Report::CREATED : ($log->isDirty() ? Report::UPDATED : Report::UNCHANGED);
        if ($outcome !== Report::UNCHANGED) {
            $log->save();
        }

        $context->refs->put('email_log', $id, 'weekly_reminder_log', $log->id);
        $context->report->count('reminder_logs', $outcome);
    }
}
