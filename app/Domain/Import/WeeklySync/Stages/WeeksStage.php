<?php

namespace App\Domain\Import\WeeklySync\Stages;

use App\Domain\Import\WeeklySync\WeeklySyncContext;
use App\Domain\Import\WeeklySync\WeeklySyncDump;
use App\Domain\Import\WeeklySync\WeeklySyncImportReport as Report;
use App\Domain\Import\WeeklySync\WeeklySyncNames;
use App\Domain\Weeklies\Report\WeeklyReport;
use App\Domain\Weeklies\WeeklyCalendar;
use App\Enums\WeeklyAudioSectionKind;
use App\Enums\WeeklyCycleStatus;
use App\Enums\WeeklyEntrySource;
use App\Enums\WeeklyExemptionReason;
use App\Enums\WeeklyJobState;
use App\Models\Client;
use App\Models\ClientSatisfactionSnapshot;
use App\Models\WeeklyAudioSection;
use App\Models\WeeklyCycle;
use App\Models\WeeklyEntry;
use App\Models\WeeklyExemption;
use App\Models\WeeklySubmission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Semanas y todo lo suyo (D-149 y D-214):
 *   - la semana, con el número y la etiqueta de Audax (WeeklyCalendar) y las fechas y el plazo de
 *     WeeklySync. Casa por import_refs o por la fecha de inicio (Audax puede haber abierto ya la
 *     semana en curso). Una semana activa de WeeklySync entra cerrada si Audax ya tiene otra activa,
 *   - el informe estructurado con los ids de cliente reescritos a los de Audax
 *     (WeeklyReport::fromWeeklySync) y su texto,
 *   - quién debía enviar (`expected_user_ids`), reconstruido con la regla de WeeklySync (D-215),
 *   - las exenciones (`excused_user_ids` → motivo «ausencia»),
 *   - el audio completo y por secciones, con los MP3 copiados al disco privado,
 *   - los envíos y sus apuntes por cliente, y los borradores sin enviar,
 *   - la satisfacción de cada cliente semana a semana y la actual.
 * Lo escrito en Audax (envíos, exenciones o satisfacción que no vienen de la importación) nunca se
 * toca.
 */
final class WeeksStage
{
    public const string IMPORTED_NOTE = 'Importada de WeeklySync (ausente al cerrar la semana).';

    public const string SATISFACTION_RULE = 'weeklysync_import';

    public const int CHUNK = 100;

    public function __construct(private readonly WeeklyCalendar $calendar) {}

    public function run(WeeklySyncContext $context): void
    {
        $weeks = $context->rows('week_cycles');
        usort($weeks, fn (array $a, array $b): int => strcmp((string) ($a['start_date'] ?? ''), (string) ($b['start_date'] ?? '')));

        DB::transaction(function () use ($context, $weeks): void {
            foreach ($weeks as $week) {
                $this->week($context, $week);
            }
        });

        $this->submissions($context);
        $this->drafts($context);

        DB::transaction(function () use ($context, $weeks): void {
            $this->satisfaction($context, $weeks);
        });
    }

    // ---------------------------------------------------------------------------------------
    // Semanas
    // ---------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $week
     */
    private function week(WeeklySyncContext $context, array $week): void
    {
        $id = WeeklySyncContext::id($week['id'] ?? null);
        $start = WeeklySyncContext::date($week['start_date'] ?? null);

        if ($start === null) {
            $context->report->skip('cycles', 'Semanas sin fecha de inicio');

            return;
        }

        $period = $this->calendar->periodFor($start);
        $end = WeeklySyncContext::date($week['end_date'] ?? null) ?? $period->end->toDateString();
        $deadline = WeeklySyncContext::date($week['deadline_date'] ?? null) ?? $end;

        $local = $context->refs->find('week', $id);
        $cycle = $local !== null ? WeeklyCycle::query()->find($local) : null;
        $cycle ??= WeeklyCycle::query()->whereDate('start_date', $start)->first();

        $clash = WeeklyCycle::query()->where('number', $period->number)->when($cycle !== null, fn ($q) => $q->whereKeyNot($cycle?->id))->exists();

        if ($clash) {
            $context->report->skip('cycles', 'Semanas con el número de otra semana de Audax');
            $context->report->warn("La semana {$period->number} de WeeklySync choca con otra semana de Audax con ese número: no se importa.");

            return;
        }

        $created = $cycle === null;
        $cycle ??= new WeeklyCycle;

        $status = strtoupper(WeeklySyncContext::str($week['status'] ?? '')) === 'ACTIVE' ? WeeklyCycleStatus::Active : WeeklyCycleStatus::Closed;

        if ($status === WeeklyCycleStatus::Active && WeeklyCycle::query()->active()->when(! $created, fn ($q) => $q->whereKeyNot($cycle->id))->exists()) {
            $status = WeeklyCycleStatus::Closed;
            $context->report->warn("La semana {$period->number} está activa en WeeklySync, pero Audax ya tiene otra activa: entra cerrada.");
        }

        $updatedAt = WeeklySyncContext::instant($week['updated_at'] ?? null);
        $structured = is_array($week['structured_report'] ?? null) ? $week['structured_report'] : null;
        $text = WeeklySyncContext::nullableStr($week['final_report_text'] ?? null);

        $cycle->fill([
            'number' => $period->number,
            'label' => $period->label,
            'start_date' => $start,
            'end_date' => $end,
            'deadline_date' => $deadline,
            'status' => $status,
        ]);

        if ($structured !== null || $text !== null) {
            $report = $structured !== null ? $this->report($context, $structured) : null;
            $cycle->fill([
                'report' => $report,
                'report_text' => $text,
                'submission_count_at_generation' => is_numeric($week['submission_count_at_generation'] ?? null) ? (int) $week['submission_count_at_generation'] : null,
                'report_state' => WeeklyJobState::Done,
                'report_error' => null,
            ]);

            if ($cycle->report_generated_at === null) {
                $cycle->report_generated_at = $updatedAt;
            }
        }

        if ($status === WeeklyCycleStatus::Closed) {
            $cycle->expected_user_ids = $this->expected($context, $week, $deadline);

            if ($cycle->closed_at === null) {
                $cycle->closed_at = $updatedAt ?? CarbonImmutable::parse($deadline.' 23:59:59', WeeklyCalendar::TIMEZONE)->utc();
            }
        } else {
            $cycle->forceFill(['expected_user_ids' => null, 'closed_at' => null, 'closed_by' => null]);
        }

        if ($created && ($createdAt = WeeklySyncContext::instant($week['created_at'] ?? null)) !== null) {
            $cycle->created_at = $createdAt;
        }

        $dirty = $created || $cycle->isDirty();
        if ($dirty) {
            $cycle->save();
        }

        $context->refs->put('week', $id, 'weekly_cycle', $cycle->id);
        $context->cycles[$id] = $cycle->id;
        $context->importedCycles[$cycle->id] = true;

        $audioChanged = $this->audio($context, $cycle, $week, $structured, $updatedAt);
        $exemptionsChanged = $this->exemptions($context, $cycle, $week);

        $context->report->count('cycles', match (true) {
            $created => Report::CREATED,
            $dirty || $audioChanged || $exemptionsChanged => Report::UPDATED,
            default => Report::UNCHANGED,
        });
    }

    /**
     * @param  array<string, mixed>  $structured
     * @return array<string, mixed>
     */
    private function report(WeeklySyncContext $context, array $structured): array
    {
        return WeeklyReport::fromWeeklySync($structured, function (?string $clientId, string $name) use ($context): ?int {
            $local = $context->client($clientId) ?? $context->clientsByName[WeeklySyncNames::client($name)] ?? null;

            if ($local === null && $name !== '') {
                $context->report->warn("Cliente del informe sin pareja: «{$name}» (queda sin enlace a su ficha).");
            }

            return $local;
        })->toArray();
    }

    /**
     * Quién debía enviar, con la regla de WeeklySync (WeeklysList.tsx isUserEligibleForWeek): las
     * cuentas que no están pendientes y que se unieron antes del final del plazo, menos las
     * excusadas al cerrar. Las personas que se quedan fuera de la importación no cuentan.
     *
     * @param  array<string, mixed>  $week
     * @return list<int>
     */
    private function expected(WeeklySyncContext $context, array $week, string $deadline): array
    {
        $end = CarbonImmutable::parse($deadline.' 23:59:59', WeeklyCalendar::TIMEZONE);
        $excused = array_map(WeeklySyncContext::id(...), is_array($week['excused_user_ids'] ?? null) ? $week['excused_user_ids'] : []);
        $expected = [];

        foreach ($context->dump->rows('users') as $user) {
            $id = WeeklySyncContext::id($user['id'] ?? null);
            $joined = WeeklySyncContext::instant($user['joined_at'] ?? null);

            if (strtoupper(WeeklySyncContext::str($user['account_status'] ?? '')) === 'PENDING'
                || ($joined !== null && $joined->greaterThan($end))
                || in_array($id, $excused, true)) {
                continue;
            }

            $local = $context->user($id);
            if ($local !== null) {
                $expected[$local] = $local;
            }
        }

        sort($expected);

        return $expected;
    }

    /**
     * @param  array<string, mixed>  $week
     */
    private function exemptions(WeeklySyncContext $context, WeeklyCycle $cycle, array $week): bool
    {
        $changed = false;
        $ids = is_array($week['excused_user_ids'] ?? null) ? $week['excused_user_ids'] : [];

        foreach (array_unique(array_map(WeeklySyncContext::id(...), $ids)) as $wsUser) {
            $user = $context->user($wsUser);

            if ($user === null) {
                $context->report->skip('exemptions', 'Exenciones de personas que se quedan fuera');

                continue;
            }

            $exemption = WeeklyExemption::query()->firstOrNew(['weekly_cycle_id' => $cycle->id, 'user_id' => $user]);

            if ($exemption->exists) {
                $context->report->count('exemptions', Report::UNCHANGED);

                continue;
            }

            $exemption->fill([
                'reason' => WeeklyExemptionReason::Absence,
                'absence_id' => null,
                'note' => self::IMPORTED_NOTE,
                'created_by' => null,
            ])->save();
            $context->report->count('exemptions', Report::CREATED);
            $changed = true;
        }

        return $changed;
    }

    /**
     * El audio completo y sus secciones (D-190): MP3 en weeklies/{id}/audio con nombre fijo.
     *
     * @param  array<string, mixed>  $week
     * @param  array<string, mixed>|null  $structured
     */
    private function audio(WeeklySyncContext $context, WeeklyCycle $cycle, array $week, ?array $structured, ?CarbonImmutable $at): bool
    {
        $changed = false;
        $full = WeeklySyncDump::storagePath($week['final_report_audio_url'] ?? null, WeeklySyncDump::AUDIO_BUCKET);

        if ($full !== null) {
            $copy = $context->files->copy(WeeklySyncDump::AUDIO_BUCKET, $full, "weeklies/{$cycle->id}/audio/weekly-weeklysync.mp3");

            if ($copy !== null) {
                $cycle->fill([
                    'audio_state' => WeeklyJobState::Done,
                    'audio_error' => null,
                    'audio_disk' => $copy['disk'],
                    'audio_path' => $copy['path'],
                ]);

                if ($cycle->audio_generated_at === null) {
                    $cycle->audio_generated_at = $at;
                }

                if ($cycle->isDirty()) {
                    $cycle->save();
                    $changed = true;
                }
            }
        }

        $sections = is_array($structured['audioSections'] ?? null) ? $structured['audioSections'] : [];
        $existing = $cycle->audioSections()->get()->keyBy('key');
        $seen = [];

        foreach (array_values(array_filter($sections, is_array(...))) as $position => $section) {
            $kind = WeeklyAudioSectionKind::tryFrom(strtolower(WeeklySyncContext::str($section['kind'] ?? ''))) ?? WeeklyAudioSectionKind::Client;
            $clientId = $kind === WeeklyAudioSectionKind::Client
                ? ($context->client($section['clientId'] ?? null) ?? $context->clientsByName[WeeklySyncNames::client(WeeklySyncContext::str($section['clientName'] ?? ''))] ?? null)
                : null;
            $key = match ($kind) {
                WeeklyAudioSectionKind::Intro => 'intro',
                WeeklyAudioSectionKind::Outro => 'outro',
                WeeklyAudioSectionKind::Client => $clientId !== null
                    ? "client-{$clientId}"
                    : 'client-ws-'.Str::limit(Str::slug(WeeklySyncContext::str($section['key'] ?? $position)), 40, ''),
            };

            if (isset($seen[$key])) {
                $context->report->skip('audio', 'Secciones de audio repetidas (dos clientes de WeeklySync en uno de Audax)');

                continue;
            }
            $seen[$key] = true;

            $source = WeeklySyncDump::storagePath($section['audioPath'] ?? $section['audioUrl'] ?? null, WeeklySyncDump::AUDIO_BUCKET);
            $copy = $source !== null ? $context->files->copy(WeeklySyncDump::AUDIO_BUCKET, $source, "weeklies/{$cycle->id}/audio/{$key}-weeklysync.mp3") : null;
            $seconds = $section['durationSeconds'] ?? null;

            /** @var WeeklyAudioSection $model */
            $model = $existing->pull($key) ?? new WeeklyAudioSection(['weekly_cycle_id' => $cycle->id, 'key' => $key]);
            $model->fill([
                'kind' => $kind,
                'client_id' => $clientId,
                'position' => $position,
                'script' => WeeklySyncContext::nullableStr($section['script'] ?? null),
                'disk' => $copy['disk'] ?? null,
                'path' => $copy['path'] ?? null,
                'mime' => $copy !== null ? 'audio/mpeg' : null,
                'size' => $copy['size'] ?? null,
                'duration_ms' => is_numeric($seconds) ? (int) round(((float) $seconds) * 1000) : null,
            ]);

            if (! $model->exists) {
                $model->generated_at = $at;
            }

            $outcome = ! $model->exists ? Report::CREATED : ($model->isDirty() ? Report::UPDATED : Report::UNCHANGED);
            if ($outcome !== Report::UNCHANGED) {
                $model->save();
                $changed = true;
            }
            $context->report->count('audio', $outcome);
        }

        return $changed;
    }

    // ---------------------------------------------------------------------------------------
    // Envíos y borradores
    // ---------------------------------------------------------------------------------------

    private function submissions(WeeklySyncContext $context): void
    {
        $entries = $this->groupBy($context->rows('client_report_entries'), 'submission_id');
        $imported = $this->importedSubmissions($context);

        foreach (array_chunk($this->targets($context, $context->rows('weekly_submissions'), 'submissions', 'entries', $entries, 'Envíos'), self::CHUNK, true) as $chunk) {
            DB::transaction(function () use ($context, $chunk, $entries, &$imported): void {
                foreach ($chunk as $key => $rows) {
                    $this->submission($context, $key, $rows, $entries, $imported);
                }
            });
        }
    }

    /**
     * Filas agrupadas por su destino en Audax («semana|persona»): dos personas de WeeklySync que son
     * la misma en Audax y escribieron la misma semana van al mismo envío. Las que no tienen destino
     * se cuentan como omitidas aquí.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, list<array<string, mixed>>>  $entries
     * @return array<string, non-empty-list<array<string, mixed>>>
     */
    private function targets(WeeklySyncContext $context, array $rows, string $type, string $entryType, array $entries, string $label): array
    {
        $targets = [];

        foreach ($rows as $row) {
            $user = $context->user($row['user_id'] ?? null);
            $cycle = $context->cycle($row['week_id'] ?? null);

            if ($user === null || $cycle === null) {
                $reason = $user === null ? "{$label} de personas que se quedan fuera" : "{$label} de semanas que no se importan";
                $context->report->skip($type, $reason);
                $context->report->skip($entryType, $reason, count($entries[WeeklySyncContext::id($row['id'] ?? null)] ?? []));

                continue;
            }

            $targets["{$cycle}|{$user}"][] = $row;
        }

        foreach ($targets as $group) {
            if (count($group) > 1) {
                $context->report->warn("{$label} de dos personas de WeeklySync que son la misma en Audax, la misma semana: se unen sus apuntes.", count($group) - 1);
            }
        }

        return $targets;
    }

    /**
     * @param  non-empty-list<array<string, mixed>>  $rows
     * @param  array<string, list<array<string, mixed>>>  $entries
     * @param  array<int, true>  $imported
     */
    private function submission(WeeklySyncContext $context, string $key, array $rows, array $entries, array &$imported): void
    {
        [$cycle, $user] = array_map('intval', explode('|', $key));
        $ids = array_map(fn (array $row): string => WeeklySyncContext::id($row['id'] ?? null), $rows);
        $rowEntries = array_merge(...array_map(fn (string $id): array => $entries[$id] ?? [], $ids));

        $submission = null;
        foreach ($ids as $id) {
            $local = $context->refs->find('submission', $id);
            $submission ??= $local !== null ? WeeklySubmission::query()->find($local) : null;
        }
        $submission ??= WeeklySubmission::query()->where(['weekly_cycle_id' => $cycle, 'user_id' => $user])->first();

        if ($submission !== null && ! isset($imported[$submission->id])) {
            $context->report->skip('submissions', 'Envíos que ya existen en Audax (escritos en Audax)', count($rows));
            $context->report->skip('entries', 'Envíos que ya existen en Audax (escritos en Audax)', count($rowEntries));

            return;
        }

        $created = $submission === null;
        $submission ??= new WeeklySubmission(['weekly_cycle_id' => $cycle, 'user_id' => $user]);
        $submittedAt = null;
        $createdAt = null;
        $general = [];
        foreach ($rows as $row) {
            $at = WeeklySyncContext::instant($row['submitted_at'] ?? null) ?? WeeklySyncContext::instant($row['created_at'] ?? null);
            $submittedAt = $at !== null && ($submittedAt === null || $at->lessThan($submittedAt)) ? $at : $submittedAt;
            $createdAt ??= WeeklySyncContext::instant($row['created_at'] ?? null);
            $general[] = (string) ($row['text_content'] ?? '');
        }

        $submission->fill([
            'submitted_at' => $submittedAt ?? now()->toImmutable(),
            'draft_saved_at' => null,
        ]);

        if ($created && $createdAt !== null) {
            $submission->created_at = $createdAt;
        }

        $dirty = $created || $submission->isDirty();
        if ($dirty) {
            $submission->save();
        }

        $imported[$submission->id] = true;
        foreach ($ids as $id) {
            $context->refs->put('submission', $id, 'weekly_submission', $submission->id);
        }
        $context->submitted[$key] = $submission->id;

        $desired = $this->desiredEntries($context, $general, $rowEntries, 'entries', 'general_entries');
        $entriesChanged = $this->syncEntries($context, $submission, $desired);

        $context->report->count('submissions', match (true) {
            $created => Report::CREATED,
            $dirty || $entriesChanged => Report::UPDATED,
            default => Report::UNCHANGED,
        }, count($rows));
    }

    private function drafts(WeeklySyncContext $context): void
    {
        $entries = $this->groupBy($context->rows('weekly_submission_draft_entries'), 'draft_id');
        $imported = $this->importedSubmissions($context);

        foreach (array_chunk($this->targets($context, $context->rows('weekly_submission_drafts'), 'drafts', 'draft_entries', $entries, 'Borradores'), self::CHUNK, true) as $chunk) {
            DB::transaction(function () use ($context, $chunk, $entries, $imported): void {
                foreach ($chunk as $key => $rows) {
                    $this->draft($context, $key, $rows, $entries, $imported);
                }
            });
        }
    }

    /**
     * Borrador sin enviar: un envío con `submitted_at` vacío (D-151). Si la persona ya envió esa
     * semana (en WeeklySync o en Audax), el borrador no se importa.
     *
     * @param  non-empty-list<array<string, mixed>>  $rows
     * @param  array<string, list<array<string, mixed>>>  $entries
     * @param  array<int, true>  $imported
     */
    private function draft(WeeklySyncContext $context, string $key, array $rows, array $entries, array $imported): void
    {
        [$cycle, $user] = array_map('intval', explode('|', $key));
        $ids = array_map(fn (array $row): string => WeeklySyncContext::id($row['id'] ?? null), $rows);
        $rowEntries = array_merge(...array_map(fn (string $id): array => $entries[$id] ?? [], $ids));

        $submission = null;
        foreach ($ids as $id) {
            $local = $context->refs->find('draft', $id);
            $submission ??= $local !== null ? WeeklySubmission::query()->find($local) : null;
        }

        $reason = isset($context->submitted[$key]) ? 'Borradores de weeklies ya enviadas' : null;

        if ($reason === null) {
            $submission ??= WeeklySubmission::query()->where(['weekly_cycle_id' => $cycle, 'user_id' => $user])->first();

            if ($submission !== null && (! isset($imported[$submission->id]) || $submission->submitted_at !== null)) {
                $reason = $submission->submitted_at !== null ? 'Borradores de weeklies ya enviadas' : 'Borradores que ya existen en Audax (escritos en Audax)';
            }
        }

        if ($reason !== null) {
            $context->report->skip('drafts', $reason, count($rows));
            $context->report->skip('draft_entries', $reason, count($rowEntries));

            return;
        }

        $created = $submission === null;
        $submission ??= new WeeklySubmission(['weekly_cycle_id' => $cycle, 'user_id' => $user]);
        $savedAt = null;
        $createdAt = null;
        $general = [];
        foreach ($rows as $row) {
            $at = WeeklySyncContext::instant($row['updated_at'] ?? null) ?? WeeklySyncContext::instant($row['created_at'] ?? null);
            $savedAt = $at !== null && ($savedAt === null || $at->greaterThan($savedAt)) ? $at : $savedAt;
            $createdAt ??= WeeklySyncContext::instant($row['created_at'] ?? null);
            $general[] = (string) ($row['general_text'] ?? '');
        }

        $submission->fill([
            'submitted_at' => null,
            'resubmitted_at' => null,
            'draft_saved_at' => $savedAt,
        ]);

        if ($created && $createdAt !== null) {
            $submission->created_at = $createdAt;
        }

        $dirty = $created || $submission->isDirty();
        if ($dirty) {
            $submission->save();
        }

        foreach ($ids as $id) {
            $context->refs->put('draft', $id, 'weekly_submission', $submission->id);
        }

        $desired = $this->desiredEntries($context, $general, $rowEntries, 'draft_entries', 'general_entries');
        $entriesChanged = $this->syncEntries($context, $submission, $desired);

        $context->report->count('drafts', match (true) {
            $created => Report::CREATED,
            $dirty || $entriesChanged => Report::UPDATED,
            default => Report::UNCHANGED,
        }, count($rows));
    }

    /**
     * Apuntes que tiene que tener el envío: «General» con el texto general y uno por cliente de
     * Audax (si dos clientes de WeeklySync son el mismo en Audax, se unen sus textos).
     *
     * @param  list<string>  $general  textos generales (uno por fila de origen)
     * @param  list<array<string, mixed>>  $entries
     * @return array<int|string, array{client_id: int|null, body: string, types: list<string>}>
     */
    private function desiredEntries(WeeklySyncContext $context, array $general, array $entries, string $type, string $generalType): array
    {
        $desired = [];
        $general = implode("\n\n", array_filter(array_map(self::body(...), $general), fn (string $text): bool => $text !== ''));

        if ($general !== '') {
            $desired['general'] = ['client_id' => null, 'body' => $general, 'types' => [$generalType]];
        }

        foreach ($entries as $entry) {
            $body = self::body((string) ($entry['text'] ?? ''));
            $client = $context->client($entry['client_id'] ?? null);

            if ($body === '') {
                $context->report->skip($type, 'Apuntes vacíos');

                continue;
            }

            if ($client === null) {
                $context->report->skip($type, 'Apuntes de clientes que no se importan');

                continue;
            }

            $key = $client;

            if (isset($desired[$key])) {
                $desired[$key]['body'] .= "\n\n".$body;
                $desired[$key]['types'][] = $type;
            } else {
                $desired[$key] = ['client_id' => $client, 'body' => $body, 'types' => [$type]];
            }
        }

        return $desired;
    }

    /**
     * Deja los apuntes del envío como $desired, conservando los ids de los que ya estaban.
     *
     * @param  array<int|string, array{client_id: int|null, body: string, types: list<string>}>  $desired
     */
    private function syncEntries(WeeklySyncContext $context, WeeklySubmission $submission, array $desired): bool
    {
        $existing = $submission->entries()->get()->keyBy(fn (WeeklyEntry $entry): int|string => $entry->client_id ?? 'general');
        $position = 0;
        $changed = false;

        foreach ($desired as $key => $entry) {
            /** @var WeeklyEntry $model */
            $model = $existing->pull($key) ?? new WeeklyEntry(['weekly_submission_id' => $submission->id]);

            $model->fill([
                'client_id' => $entry['client_id'],
                'body' => $entry['body'],
                'source' => WeeklyEntrySource::Text,
                'position' => $position++,
            ]);

            $outcome = ! $model->exists ? Report::CREATED : ($model->isDirty() ? Report::UPDATED : Report::UNCHANGED);
            if ($outcome !== Report::UNCHANGED) {
                $model->save();
                $changed = true;
            }

            foreach ($entry['types'] as $type) {
                $context->report->count($type, $outcome);
            }
        }

        foreach ($existing as $stale) {
            $stale->delete();
            $changed = true;
        }

        return $changed;
    }

    /**
     * Envíos de Audax que vienen de la importación (de un envío o de un borrador de WeeklySync).
     *
     * @return array<int, true>
     */
    private function importedSubmissions(WeeklySyncContext $context): array
    {
        $ids = [];
        foreach (['submission', 'draft'] as $kind) {
            foreach ($context->refs->all($kind) as $local) {
                $ids[$local] = true;
            }
        }

        return $ids;
    }

    // ---------------------------------------------------------------------------------------
    // Satisfacción
    // ---------------------------------------------------------------------------------------

    /**
     * La de cada cliente al cerrar cada semana (la que WeeklySync guardaba en el informe, F-093 y
     * F-132) y la actual (`clients.current_satisfaction`), salvo donde Audax ya la calcula.
     *
     * @param  list<array<string, mixed>>  $weeks
     */
    private function satisfaction(WeeklySyncContext $context, array $weeks): void
    {
        $previous = [];
        $done = [];
        $imported = array_flip($context->refs->all('satisfaction'));

        foreach ($weeks as $week) {
            $cycleId = $context->cycle($week['id'] ?? null);
            $report = $cycleId !== null ? WeeklyCycle::query()->find($cycleId, ['id', 'report', 'closed_at', 'status']) : null;

            if ($report === null || ! $report->isClosed() || ! is_array($report->report)) {
                continue;
            }

            foreach ((array) ($report->report['client_updates'] ?? []) as $update) {
                $clientId = is_array($update) && is_numeric($update['client_id'] ?? null) ? (int) $update['client_id'] : null;
                $score = is_array($update) ? ClientsStage::score($update['satisfaction_score'] ?? null) : null;

                if ($clientId === null || $score === null) {
                    continue;
                }

                if (isset($done[$cycleId.'|'.$clientId])) {
                    $context->report->skip('satisfaction', 'Satisfacción repetida (dos clientes de WeeklySync en uno de Audax)');

                    continue;
                }
                $done[$cycleId.'|'.$clientId] = true;

                $external = WeeklySyncContext::id($week['id'] ?? null).':'.$clientId;
                $snapshot = ClientSatisfactionSnapshot::query()->firstOrNew(['client_id' => $clientId, 'weekly_cycle_id' => $cycleId]);

                if ($snapshot->exists && ! isset($imported[$snapshot->id])) {
                    $context->report->skip('satisfaction', 'Satisfacción que Audax ya calculó al cerrar la semana');
                    $previous[$clientId] = $snapshot->score;

                    continue;
                }

                $before = $previous[$clientId] ?? null;
                $snapshot->fill([
                    'score' => $score,
                    'previous_score' => $before,
                    'requested_delta' => null,
                    'delta' => $before === null ? 0 : $score - $before,
                    'rule' => self::SATISFACTION_RULE,
                ]);

                if (! $snapshot->exists && $report->closed_at !== null) {
                    $snapshot->created_at = $report->closed_at;
                }

                $outcome = ! $snapshot->exists ? Report::CREATED : ($snapshot->isDirty() ? Report::UPDATED : Report::UNCHANGED);
                if ($outcome !== Report::UNCHANGED) {
                    $snapshot->save();
                }

                $context->refs->put('satisfaction', $external, 'client_satisfaction_snapshot', $snapshot->id);
                $imported[$snapshot->id] = true;
                $previous[$clientId] = $score;
                $context->report->count('satisfaction', $outcome);
            }
        }

        // La actual: la de WeeklySync, salvo en los clientes con satisfacción calculada por Audax.
        $own = ClientSatisfactionSnapshot::query()
            ->whereNotIn('id', array_keys($imported))
            ->distinct()
            ->pluck('client_id')
            ->flip();

        foreach ($context->dump->rows('clients') as $row) {
            $clientId = $context->client($row['id'] ?? null);
            $score = ClientsStage::score($row['current_satisfaction'] ?? null);

            if ($clientId === null || $score === null) {
                continue;
            }

            if (isset($own[$clientId])) {
                $context->report->skip('satisfaction_now', 'Satisfacción actual que ya calcula Audax');

                continue;
            }

            $client = Client::withTrashed()->find($clientId);
            if ($client === null) {
                continue;
            }

            $client->satisfaction_score = $score;
            $context->report->count('satisfaction_now', $client->isDirty() ? Report::UPDATED : Report::UNCHANGED);
            $client->save();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupBy(array $rows, string $column): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[WeeklySyncContext::id($row[$column] ?? null)][] = $row;
        }

        return $groups;
    }

    private static function body(string $text): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }
}
