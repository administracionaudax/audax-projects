<?php

use App\Domain\Privacy\Export\Sections\AiSummariesSection;
use App\Domain\Privacy\Export\Sections\AiUsageSection;
use App\Domain\Privacy\Export\Sections\DictationsSection;
use App\Domain\Privacy\Export\Sections\MySpaceTasksSection;
use App\Domain\Privacy\Export\Sections\WeeklyEntriesSection;
use App\Domain\Privacy\Export\Sections\WeeklyExemptionsSection;
use App\Domain\Privacy\Export\Sections\WeeklyRemindersSection;
use App\Domain\Privacy\Export\Sections\WeeklySubmissionsSection;
use App\Domain\Privacy\RetentionPolicy;
use App\Enums\AiFeature;
use App\Enums\AiSummaryKind;
use App\Enums\DictationContext;
use App\Enums\TranscriptionStatus;
use App\Enums\WeeklyEntrySource;
use App\Enums\WeeklyJobState;
use App\Models\AiSummary;
use App\Models\Client;
use App\Models\Dictation;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TaskArchive;
use App\Models\TaskSuggestionBatch;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklyEntry;
use App\Models\WeeklyExemption;
use App\Models\WeeklyReminderLog;
use App\Models\WeeklySubmission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
| RGPD de la Weekly (10.5, D-202): la exportación de datos personales lleva las weeklies de la
| persona (envíos y apuntes), sus dictados, sus exenciones, los resúmenes con IA sobre ella y los
| avisos que ha recibido; nunca lo de otra persona. Y los plazos del registro de avisos y de los
| dictados (app:prune-data), que nunca tocan las weeklies.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Europe/Madrid'));
    $this->cycle = WeeklyCycle::factory()->active('2026-10-05')->create();
    $this->elena = userWithRole('employee', ['name' => 'Elena']);
    $this->other = userWithRole('employee', ['name' => 'Otra']);
    $this->client = Client::factory()->create(['name' => 'Acme']);
    $this->rows = fn (object $section, User $user): array => iterator_to_array($section->rows($user), false);
});

it('las secciones de la Weekly están en la exportación, en orden', function () {
    expect(config('privacy.export_sections'))->toContain(
        WeeklySubmissionsSection::class,
        WeeklyEntriesSection::class,
        DictationsSection::class,
        WeeklyExemptionsSection::class,
        AiSummariesSection::class,
        WeeklyRemindersSection::class,
    );

    foreach ([new WeeklySubmissionsSection, new WeeklyEntriesSection, new DictationsSection, new WeeklyExemptionsSection, new AiSummariesSection, new WeeklyRemindersSection] as $section) {
        expect($section->description())->not->toStartWith('privacy.')
            ->and(array_filter($section->columns(), fn (string $label): bool => str_starts_with($label, 'privacy.')))->toBe([]);
    }
});

it('mis envíos y mis apuntes, con «General / Interno» y el proyecto; nada de otra persona', function () {
    $project = Project::factory()->create(['client_id' => $this->client->id, 'code' => 'ACME-BH1', 'name' => 'Bolsa']);
    // Los instantes se guardan en UTC: las 09:00 UTC son las 11:00 de Madrid.
    $mine = WeeklySubmission::factory()->submitted('2026-10-07 09:00:00')->create(['weekly_cycle_id' => $this->cycle->id, 'user_id' => $this->elena->id]);
    WeeklyEntry::factory()->create(['weekly_submission_id' => $mine->id, 'client_id' => $this->client->id, 'project_id' => $project->id, 'body' => 'Maqueta lista', 'source' => WeeklyEntrySource::Dictation]);
    WeeklyEntry::factory()->general()->create(['weekly_submission_id' => $mine->id, 'body' => 'Formación', 'position' => 1]);
    $theirs = WeeklySubmission::factory()->create(['weekly_cycle_id' => $this->cycle->id, 'user_id' => $this->other->id]);
    WeeklyEntry::factory()->create(['weekly_submission_id' => $theirs->id, 'body' => 'De otra persona']);

    $submissions = ($this->rows)(new WeeklySubmissionsSection, $this->elena);
    $entries = ($this->rows)(new WeeklyEntriesSection, $this->elena);

    expect($submissions)->toHaveCount(1)
        ->and($submissions[0])->toMatchArray(['week' => 'W41-26', 'deadline_date' => '2026-10-09', 'submitted_at' => '2026-10-07T11:00:00+02:00', 'entries' => 2])
        ->and($entries)->toHaveCount(2)
        ->and($entries[0])->toMatchArray(['week' => 'W41-26', 'client' => 'Acme', 'project' => 'ACME-BH1 · Bolsa', 'body' => 'Maqueta lista', 'source' => WeeklyEntrySource::Dictation->label()])
        ->and($entries[1])->toMatchArray(['client' => 'General / Interno', 'project' => null, 'body' => 'Formación'])
        ->and(json_encode($entries))->not->toContain('De otra persona');
});

it('mis dictados (sin audio), mis exenciones y los avisos que he recibido', function () {
    Dictation::query()->create(['user_id' => $this->elena->id, 'context' => DictationContext::WeeklyEntry, 'weekly_cycle_id' => $this->cycle->id, 'client_id' => $this->client->id, 'status' => TranscriptionStatus::Done, 'raw_text' => 'eh la maqueta', 'text' => 'La maqueta', 'audio_duration_ms' => 4200, 'transcribed_at' => now()]);
    Dictation::query()->create(['user_id' => $this->other->id, 'context' => DictationContext::WeeklyEntry, 'status' => TranscriptionStatus::Done, 'text' => 'Ajeno']);
    WeeklyExemption::factory()->create(['weekly_cycle_id' => $this->cycle->id, 'user_id' => $this->elena->id, 'note' => 'Formación', 'created_by' => $this->other->id]);
    WeeklyReminderLog::query()->create(['weekly_cycle_id' => $this->cycle->id, 'user_id' => $this->elena->id, 'recipient_name' => 'Elena', 'recipient_email' => 'elena@example.com', 'template' => 'manual', 'channel' => 'email', 'trigger_key' => 'manual:1', 'status' => 'sent', 'sent_by' => $this->other->id]);
    WeeklyReminderLog::query()->create(['weekly_cycle_id' => $this->cycle->id, 'user_id' => $this->other->id, 'template' => 'manual', 'channel' => 'email', 'trigger_key' => 'manual:1', 'status' => 'sent']);

    $dictations = ($this->rows)(new DictationsSection, $this->elena);
    $exemptions = ($this->rows)(new WeeklyExemptionsSection, $this->elena);
    $reminders = ($this->rows)(new WeeklyRemindersSection, $this->elena);

    expect($dictations)->toHaveCount(1)
        ->and($dictations[0])->toMatchArray(['week' => 'W41-26', 'client' => 'Acme', 'status' => 'Hecho', 'raw_text' => 'eh la maqueta', 'text' => 'La maqueta', 'audio_duration_ms' => 4200])
        ->and($exemptions)->toHaveCount(1)
        ->and($exemptions[0])->toMatchArray(['week' => 'W41-26', 'reason' => 'Exención manual', 'note' => 'Formación', 'by_myself' => false])
        ->and(array_keys($exemptions[0]))->not->toContain('created_by')
        ->and($reminders)->toHaveCount(1)
        ->and($reminders[0])->toMatchArray(['week' => 'W41-26', 'template' => 'Manual', 'channel' => 'Email', 'status' => 'Enviado', 'recipient_email' => 'elena@example.com'])
        ->and(json_encode($reminders))->not->toContain((string) $this->other->name);
});

it('los resúmenes con IA sobre mí: desempeño, actividad por cliente y mi frase del análisis del equipo', function () {
    $base = ['state' => WeeklyJobState::Done, 'model' => 'gemini-test', 'generated_at' => now()];
    AiSummary::query()->create([...$base, 'kind' => AiSummaryKind::PersonPerformance, 'subject_type' => $this->elena->getMorphClass(), 'subject_id' => $this->elena->id, 'content' => 'Constante y puntual.']);
    AiSummary::query()->create([...$base, 'kind' => AiSummaryKind::PersonClientActivity, 'subject_type' => $this->elena->getMorphClass(), 'subject_id' => $this->elena->id, 'items' => [(string) $this->client->id => 'Lleva la maqueta.']]);
    AiSummary::query()->create([...$base, 'kind' => AiSummaryKind::ClientTeamActivity, 'subject_type' => $this->client->getMorphClass(), 'subject_id' => $this->client->id, 'items' => [(string) $this->elena->id => 'Elena cierra el diseño.', (string) $this->other->id => 'Otra revisa.']]);
    AiSummary::query()->create([...$base, 'kind' => AiSummaryKind::PersonPerformance, 'subject_type' => $this->other->getMorphClass(), 'subject_id' => $this->other->id, 'content' => 'De otra persona.']);

    $rows = ($this->rows)(new AiSummariesSection, $this->elena);

    expect($rows)->toHaveCount(3)
        ->and($rows[0])->toMatchArray(['kind' => 'Tu desempeño', 'content' => 'Constante y puntual.', 'model' => 'gemini-test'])
        ->and($rows[1])->toMatchArray(['kind' => 'Tu actividad por cliente', 'content' => 'Acme: Lleva la maqueta.'])
        ->and($rows[2])->toMatchArray(['kind' => 'Actividad del equipo en un cliente', 'about' => 'Acme', 'content' => 'Elena cierra el diseño.'])
        ->and(json_encode($rows))->not->toContain('Otra revisa')
        ->and(json_encode($rows))->not->toContain('De otra persona');
});

it('app:prune-data borra el registro de avisos y los dictados pasado su plazo, y nunca las weeklies', function () {
    Storage::fake('local');
    $submission = WeeklySubmission::factory()->submitted()->create(['weekly_cycle_id' => $this->cycle->id, 'user_id' => $this->elena->id]);
    WeeklyEntry::factory()->create(['weekly_submission_id' => $submission->id, 'body' => 'Se queda']);
    $log = fn (string $created) => DB::table('weekly_reminder_logs')->insert(['user_id' => $this->elena->id, 'template' => 'automatic', 'channel' => 'email', 'trigger_key' => "rule:{$created}", 'status' => 'sent', 'created_at' => $created, 'updated_at' => $created]);
    $log('2025-09-01 10:00:00');
    $log('2026-09-01 10:00:00');
    Storage::disk('local')->put('dictations/viejo.webm', 'audio');
    $dictation = fn (string $created, ?string $path = null) => DB::table('dictations')->insertGetId(['user_id' => $this->elena->id, 'context' => 'weekly_entry', 'status' => 'done', 'disk' => $path === null ? null : 'local', 'path' => $path, 'text' => 'texto', 'attempts' => 0, 'created_at' => $created, 'updated_at' => $created]);
    $old = $dictation('2026-06-01 10:00:00', 'dictations/viejo.webm');
    $recent = $dictation('2026-09-20 10:00:00');

    expect(app(RetentionPolicy::class)->months(RetentionPolicy::WEEKLY_REMINDER_LOGS))->toBe(12)
        ->and(app(RetentionPolicy::class)->months(RetentionPolicy::DICTATIONS))->toBe(3);

    $this->artisan('app:prune-data')->assertSuccessful();

    expect(DB::table('weekly_reminder_logs')->pluck('trigger_key')->all())->toBe(['rule:2026-09-01 10:00:00'])
        ->and(DB::table('dictations')->pluck('id')->all())->toBe([$recent])
        ->and(DB::table('dictations')->where('id', $old)->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists('dictations/viejo.webm'))->toBeFalse()
        ->and(WeeklySubmission::query()->count())->toBe(1)
        ->and(WeeklyEntry::query()->count())->toBe(1);

    // Con un plazo más largo, no se borra lo de dentro.
    Setting::set('retention_weekly_reminder_logs_months', 120);
    $this->artisan('app:prune-data')->assertSuccessful();
    expect(DB::table('weekly_reminder_logs')->count())->toBe(1);
});

it('lo mío de las tareas de Mi espacio: las sugeridas sin crear y las que he archivado (10.6)', function () {
    $task = Task::factory()->create(['title' => 'Tarea compartida']);
    TaskArchive::query()->create(['user_id' => $this->elena->id, 'task_id' => $task->id]);
    TaskArchive::query()->create(['user_id' => $this->other->id, 'task_id' => $task->id]);
    TaskSuggestionBatch::query()->create([
        'user_id' => $this->elena->id, 'weekly_cycle_id' => $this->cycle->id, 'state' => WeeklyJobState::Done,
        'items' => [['key' => 'a', 'title' => 'Revisar el banner', 'client_id' => $this->client->id, 'client_name' => 'Acme', 'author_name' => 'Raúl']],
        'generated_at' => now(),
    ]);
    TaskSuggestionBatch::query()->create(['user_id' => $this->other->id, 'state' => WeeklyJobState::Done, 'items' => [['key' => 'b', 'title' => 'De otra persona']]]);

    $section = new MySpaceTasksSection;
    $rows = ($this->rows)($section, $this->elena);

    expect(config('privacy.export_sections'))->toContain(MySpaceTasksSection::class)
        ->and($section->description())->not->toStartWith('privacy.')
        ->and($rows)->toHaveCount(2)
        ->and($rows[0])->toMatchArray(['title' => 'Revisar el banner', 'client' => 'Acme', 'week' => 'W41-26', 'author' => 'Raúl'])
        ->and($rows[1])->toMatchArray(['title' => 'Tarea compartida', 'kind' => 'Tarea archivada de mi lista'])
        ->and(json_encode($rows))->not->toContain('De otra persona');
});

it('el uso de la IA: lo que pedí y lo que trata sobre mí, sin lo de otras personas (D-225)', function () {
    $usage = fn (?User $by, ?User $about, string $feature) => DB::table('ai_usage')->insertGetId([
        'user_id' => $by?->id, 'provider' => 'gemini', 'model' => 'gemini-2.5-flash', 'feature' => $feature, 'status' => 'success',
        'subject_type' => $about?->getMorphClass(), 'subject_id' => $about?->id,
        'metadata' => $about === null ? null : json_encode(['target_user_id' => $about->id]), 'created_at' => '2026-10-01 10:00:00',
    ]);
    $mine = $usage($this->elena, null, 'assistant');
    $about = $usage($this->other, $this->elena, 'person_performance');
    $usage($this->other, null, 'assistant');

    expect(config('privacy.export_sections'))->toContain(AiUsageSection::class);
    $rows = ($this->rows)(new AiUsageSection, $this->elena);

    expect(array_column($rows, 'id'))->toBe([$mine, $about])
        ->and($rows[0]['relation'])->toBe(__('privacy.export.weeklies.ai_usage_relation.mine'))
        ->and($rows[1]['relation'])->toBe(__('privacy.export.weeklies.ai_usage_relation.about'))
        ->and($rows[0]['feature'])->toBe(AiFeature::Assistant->label());
});

it('app:prune-data anonimiza el uso de la IA pasado su plazo y conserva el coste (D-225)', function () {
    $usage = fn (string $created) => DB::table('ai_usage')->insertGetId([
        'user_id' => $this->other->id, 'provider' => 'gemini', 'model' => 'gemini-2.5-flash', 'feature' => 'person_performance', 'status' => 'success',
        'subject_type' => $this->elena->getMorphClass(), 'subject_id' => $this->elena->id, 'estimated_cost_usd' => '0.012000',
        'metadata' => json_encode(['target_user_id' => $this->elena->id]), 'created_at' => $created,
    ]);
    $old = $usage('2025-09-01 10:00:00');
    $recent = $usage('2026-09-01 10:00:00');

    expect(app(RetentionPolicy::class)->months(RetentionPolicy::AI_USAGE))->toBe(12);

    $this->artisan('app:prune-data')->assertSuccessful();

    $oldRow = DB::table('ai_usage')->find($old);
    expect($oldRow->user_id)->toBeNull()
        ->and($oldRow->subject_type)->toBeNull()
        ->and($oldRow->subject_id)->toBeNull()
        ->and($oldRow->metadata)->toBeNull()
        ->and((float) $oldRow->estimated_cost_usd)->toBe(0.012)
        ->and(DB::table('ai_usage')->find($recent)->user_id)->toBe($this->other->id)
        ->and(DB::table('ai_usage')->count())->toBe(2);
});
