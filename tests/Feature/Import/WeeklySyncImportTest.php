<?php

use App\Domain\Import\WeeklySync\Stages\WeeksStage;
use App\Domain\Import\WeeklySync\WeeklySyncImporter;
use App\Domain\Import\WeeklySync\WeeklySyncImportReport;
use App\Domain\Import\WeeklySync\WeeklySyncMappings;
use App\Enums\AiFeature;
use App\Enums\AiProvider;
use App\Enums\SuggestionReaction;
use App\Enums\SuggestionStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatusCategory;
use App\Enums\WeeklyAudioSectionKind;
use App\Enums\WeeklyClientStatus;
use App\Enums\WeeklyCycleStatus;
use App\Enums\WeeklyExemptionReason;
use App\Enums\WeeklyReminderChannel;
use App\Enums\WeeklyReminderStatus;
use App\Models\AiUsage;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\ClientSatisfactionSnapshot;
use App\Models\HelpFaq;
use App\Models\HelpFaqSection;
use App\Models\HelpManualUpdate;
use App\Models\HelpRelease;
use App\Models\HelpReleaseChange;
use App\Models\HelpTutorial;
use App\Models\HelpUpdateLike;
use App\Models\HourBank;
use App\Models\ImportRef;
use App\Models\Project;
use App\Models\Setting;
use App\Models\SuggestionBoard;
use App\Models\SuggestionCategory;
use App\Models\SuggestionComment;
use App\Models\SuggestionCommentReaction;
use App\Models\SuggestionPost;
use App\Models\SuggestionStatusEvent;
use App\Models\SuggestionVote;
use App\Models\Task;
use App\Models\TaskArchive;
use App\Models\User;
use App\Models\WeeklyAudioSection;
use App\Models\WeeklyCycle;
use App\Models\WeeklyEntry;
use App\Models\WeeklyExemption;
use App\Models\WeeklyReminderLog;
use App\Models\WeeklyReminderRule;
use App\Models\WeeklySubmission;
use App\Support\RichText;
use Carbon\CarbonImmutable;
use Database\Seeders\DefaultSettingsSeeder;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\TaskStatusesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/*
| Importación de WeeklySync (D-149, D-213 a D-220) con el volcado INVENTADO de
| tests/fixtures/weeklysync (lo genera el volcador; ver WeeklySyncDumpTest). En Audax ya están Ana,
| Bruno y Carla y los clientes «Manzanas Pérez», «Peras Gómez S.L.», «Kiwis del Norte» (proyectos
| KIWI-FE1 y KIWI-WE2), «Cítricos Levante» y «Uvas del Sur» (bolsa con la factura F000123).
| «Hoy» es el miércoles 07/10/2026 en Madrid.
*/

const WS_FIXTURE = 'tests/fixtures/weeklysync';

function wsId(string $prefix, int $n): string
{
    return sprintf('%s-0000-4000-8000-%012d', $prefix, $n);
}

function runWeeklySyncImport(bool $dryRun = false, ?string $directory = null, ?WeeklySyncMappings $mappings = null): WeeklySyncImportReport
{
    $directory ??= base_path(WS_FIXTURE);
    $mappings ??= WeeklySyncMappings::load($directory.'/personas.json', $directory.'/clientes.json');

    return app(WeeklySyncImporter::class)->run($directory, $mappings, $dryRun);
}

/**
 * Recuento de las tablas que toca la importación.
 *
 * @return array<string, int>
 */
function weeklySyncTableCounts(): array
{
    $tables = ['users', 'clients', 'weekly_cycles', 'weekly_submissions', 'weekly_entries', 'weekly_exemptions', 'weekly_audio_sections',
        'client_satisfaction_snapshots', 'tasks', 'task_archives', 'weekly_reminder_rules', 'weekly_reminder_logs', 'help_releases',
        'help_release_changes', 'help_manual_updates', 'help_update_likes', 'help_tutorials', 'help_faq_sections', 'help_faqs',
        'suggestion_boards', 'suggestion_categories', 'suggestion_posts', 'suggestion_votes', 'suggestion_comments',
        'suggestion_comment_reactions', 'suggestion_status_events', 'attachments', 'ai_usage', 'import_refs'];

    return collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00', 'Europe/Madrid'));
    $this->seed([DefaultSettingsSeeder::class, DepartmentsSeeder::class, TaskStatusesSeeder::class]);
    Storage::fake('local');
    Notification::fake();

    $this->ana = User::factory()->admin()->create(['name' => 'Ana de Audax', 'email' => 'ana@audax.test']);
    $this->bruno = User::factory()->employee()->create(['name' => 'Bruno de Audax', 'email' => 'bruno@audax.test']);
    $this->carla = User::factory()->employee()->create(['name' => 'Carla de Audax', 'email' => 'carla@audax.test', 'job_title' => null]);

    $this->manzanas = Client::factory()->create(['name' => 'Manzanas Pérez', 'icon' => null]);
    $this->peras = Client::factory()->create(['name' => 'Peras Gómez S.L.', 'icon' => '🍏']);
    $this->kiwis = Client::factory()->create(['name' => 'Kiwis del Norte', 'icon' => null]);
    $this->citricos = Client::factory()->create(['name' => 'Cítricos Levante', 'icon' => null]);
    $this->uvas = Client::factory()->create(['name' => 'Uvas del Sur', 'icon' => null]);

    $this->manzanasProject = Project::factory()->create(['client_id' => $this->manzanas->id, 'code' => 'MANZ-WE1', 'owner_user_id' => $this->ana->id]);
    Project::factory()->create(['client_id' => $this->kiwis->id, 'code' => 'KIWI-FE1', 'owner_user_id' => $this->ana->id]);
    Project::factory()->create(['client_id' => $this->kiwis->id, 'code' => 'KIWI-WE2', 'owner_user_id' => $this->ana->id]);
    $uvasProject = Project::factory()->hourBank()->create(['client_id' => $this->uvas->id, 'code' => 'UVAS-BH', 'owner_user_id' => $this->ana->id]);
    HourBank::factory()->create(['project_id' => $uvasProject->id, 'invoice_reference' => 'F000123']);
});

test('importa personas, clientes, semanas, envíos, borradores, exenciones y satisfacción', function () {
    $usersBefore = User::query()->count();
    $report = runWeeklySyncImport();

    // Personas: Ana por su correo, Bruno por su identidad de Google, Carla por el fichero de
    // personas; Diego se crea inactivo; Elena (pendiente, sin nada) y Fede (fuera) no.
    expect(User::query()->count())->toBe($usersBefore + 1);
    $diego = User::query()->where('email', 'diego@antiguo.test')->sole();
    expect($diego->is_active)->toBeFalse()
        ->and($diego->job_title)->toBe('Redactor')
        ->and($diego->hasRole('employee'))->toBeTrue()
        ->and($this->carla->fresh()->job_title)->toBe('Diseñadora')
        ->and($this->ana->fresh()->job_title)->toBe('Directora')
        ->and(User::query()->where('email', 'elena@pendiente.test')->exists())->toBeFalse()
        ->and(User::query()->where('email', 'fede@pruebas.test')->exists())->toBeFalse();

    // Clientes: por nombre normalizado, por códigos de proyecto, por factura y por el fichero; el
    // que no casa, inactivo.
    $fresas = Client::query()->where('name', 'Prospecto Fresas')->sole();
    expect(Client::query()->count())->toBe(6)
        ->and($fresas->is_active)->toBeFalse()
        ->and($fresas->icon)->toBe('🍓')
        ->and($this->manzanas->fresh()->icon)->toBe('🍎')
        ->and($this->peras->fresh()->icon)->toBe('🍏')
        ->and(ImportRef::query()->where(['source' => 'weeklysync', 'kind' => 'client', 'external_id' => wsId('22222222', 3)])->value('local_id'))->toBe($this->kiwis->id)
        ->and(ImportRef::query()->where(['source' => 'weeklysync', 'kind' => 'client', 'external_id' => wsId('22222222', 6)])->value('local_id'))->toBe($this->uvas->id)
        ->and(ImportRef::query()->where(['source' => 'weeklysync', 'kind' => 'client', 'external_id' => wsId('22222222', 7)])->value('local_id'))->toBe($this->citricos->id)
        ->and($report->warnings())->toHaveKey('Cliente casado por sus códigos de proyecto: «Kiwis» de WeeklySync → «Kiwis del Norte». Revísalo.')
        ->and($report->warnings())->toHaveKey('Cliente casado por su factura: «Uvas» de WeeklySync → «Uvas del Sur». Revísalo.');

    // Semanas: número y etiqueta de Audax; la 41 sigue activa.
    $w39 = WeeklyCycle::query()->where('number', 'W39-26')->sole();
    $w40 = WeeklyCycle::query()->where('number', 'W40-26')->sole();
    $w41 = WeeklyCycle::query()->where('number', 'W41-26')->sole();
    expect($w39->status)->toBe(WeeklyCycleStatus::Closed)
        ->and($w39->start_date->toDateString())->toBe('2026-09-21')
        ->and($w39->label)->toContain('Semana 39')
        ->and($w39->report_text)->toBe("## Weekly 39\n\nSemana tranquila.")
        ->and($w39->closed_at?->toIso8601String())->toBe('2026-09-26T09:30:00+00:00')
        ->and($w40->deadline_date->toDateString())->toBe('2026-10-05')
        ->and($w41->status)->toBe(WeeklyCycleStatus::Active)
        ->and($w41->expected_user_ids)->toBeNull();

    // Quién debía enviar (regla de WeeklySync): sin la excusada ni la pendiente ni quien llegó después.
    expect($w39->expected_user_ids)->toBe(collect([$this->ana->id, $this->bruno->id, $diego->id])->sort()->values()->all())
        ->and($w40->expected_user_ids)->toBe(collect([$this->ana->id, $this->bruno->id, $this->carla->id, $diego->id])->sort()->values()->all());

    // El informe con los ids de Audax (también el que solo traía el nombre) y los estados nuevos.
    $updates = collect($w39->reportData()?->clientUpdates);
    expect($updates->pluck('clientId')->all())->toBe([$this->manzanas->id, $this->peras->id, $this->kiwis->id])
        ->and($updates->pluck('status')->all())->toBe([WeeklyClientStatus::Risk, WeeklyClientStatus::OnTrack, WeeklyClientStatus::Blocked])
        ->and($updates->first()->milestones[0]->label)->toBe('Entrega de maquetas')
        ->and($w39->reportData()?->teamRisks)->toBe(['Carla de vacaciones'])
        ->and(collect($w40->reportData()?->clientUpdates)->pluck('clientId')->all())->toBe([$this->manzanas->id, $this->peras->id, $this->citricos->id, null])
        ->and($report->warnings())->toHaveKey('Cliente del informe sin pareja: «Cliente Borrado» (queda sin enlace a su ficha).');

    // Exención de la persona excusada.
    $exemption = WeeklyExemption::query()->where(['weekly_cycle_id' => $w39->id, 'user_id' => $this->carla->id])->sole();
    expect($exemption->reason)->toBe(WeeklyExemptionReason::Absence)
        ->and($exemption->note)->toBe(WeeksStage::IMPORTED_NOTE)
        ->and(WeeklyExemption::query()->count())->toBe(1);

    // Envíos con sus apuntes; lo vacío y lo de Fede no entran; dos clientes de WeeklySync en uno.
    $anaW39 = WeeklySubmission::query()->where(['weekly_cycle_id' => $w39->id, 'user_id' => $this->ana->id])->sole();
    expect($anaW39->submitted_at?->toIso8601String())->toBe('2026-09-24T15:00:00+00:00')
        ->and($anaW39->entries()->orderBy('position')->get()->map(fn (WeeklyEntry $e) => [$e->client_id, $e->body])->all())->toBe([
            [null, 'Semana de reuniones internas.'],
            [$this->manzanas->id, 'Manzanas: revisión de maquetas.'],
            [$this->peras->id, 'Peras: sin novedades.'],
        ]);

    $anaW40 = WeeklySubmission::query()->where(['weekly_cycle_id' => $w40->id, 'user_id' => $this->ana->id])->sole();
    expect($anaW40->entries()->orderBy('position')->get()->map(fn (WeeklyEntry $e) => [$e->client_id, $e->body])->all())->toBe([
        [$this->citricos->id, "Limones: arranque.\n\nNaranjas: presupuesto."],
        [$this->uvas->id, 'Uvas: bolsa casi agotada.'],
    ]);

    $brunoW39 = WeeklySubmission::query()->where(['weekly_cycle_id' => $w39->id, 'user_id' => $this->bruno->id])->sole();
    expect($brunoW39->entries()->pluck('client_id')->all())->toBe([$this->kiwis->id, $fresas->id])
        ->and(WeeklySubmission::query()->where(['weekly_cycle_id' => $w40->id, 'user_id' => $diego->id])->sole()->isSubmitted())->toBeTrue()
        ->and(WeeklySubmission::query()->whereNotNull('submitted_at')->count())->toBe(5);

    // Borradores: el de Ana en la semana activa; el de Bruno no (ya envió).
    $draft = WeeklySubmission::query()->where(['weekly_cycle_id' => $w41->id, 'user_id' => $this->ana->id])->sole();
    expect($draft->submitted_at)->toBeNull()
        ->and($draft->draft_saved_at?->toIso8601String())->toBe('2026-10-06T17:45:00+00:00')
        ->and($draft->entries()->orderBy('position')->get()->map(fn (WeeklyEntry $e) => [$e->client_id, $e->body])->all())->toBe([
            [null, 'Borrador general de Ana.'],
            [$this->manzanas->id, 'Manzanas: borrador.'],
        ])
        ->and(WeeklySubmission::query()->where(['weekly_cycle_id' => $w41->id, 'user_id' => $this->bruno->id])->sole()->isSubmitted())->toBeTrue();

    // Satisfacción por semana (la serie de la ficha) y la actual.
    $manzanas = ClientSatisfactionSnapshot::query()->where('client_id', $this->manzanas->id)->orderBy('weekly_cycle_id')->get();
    expect($manzanas->map(fn ($s) => [$s->score, $s->previous_score, $s->delta, $s->rule])->all())->toBe([
        [65, null, 0, WeeksStage::SATISFACTION_RULE],
        [70, 65, 5, WeeksStage::SATISFACTION_RULE],
    ])
        ->and(ClientSatisfactionSnapshot::query()->count())->toBe(5)
        ->and($this->manzanas->fresh()->satisfaction_score)->toBe(72)
        ->and($this->peras->fresh()->satisfaction_score)->toBe(41)
        ->and($this->citricos->fresh()->satisfaction_score)->toBe(60)
        ->and($fresas->satisfaction_score)->toBe(50);

    // Cuadra con el manifiesto.
    expect($report->hasDifferences())->toBeFalse()
        ->and($report->get('people', WeeklySyncImportReport::SKIPPED))->toBe(2)
        ->and($report->get('entries', WeeklySyncImportReport::SKIPPED))->toBe(2)
        ->and($report->skipped())->toHaveKey('Borradores de weeklies ya enviadas');
});

test('copia los audios del informe a weeklies/{id}/audio con sus secciones', function () {
    $report = runWeeklySyncImport();
    $w39 = WeeklyCycle::query()->where('number', 'W39-26')->sole();

    expect($w39->audio_path)->toBe("weeklies/{$w39->id}/audio/weekly-weeklysync.mp3")
        ->and($w39->audio_disk)->toBe('local')
        ->and(Storage::disk('local')->get($w39->audio_path))->toBe(File::get(base_path(WS_FIXTURE.'/storage/audio-submissions/weekly-reports/'.wsId('33333333', 1).'-full.mp3')));

    $sections = WeeklyAudioSection::query()->where('weekly_cycle_id', $w39->id)->orderBy('position')->get();
    expect($sections->pluck('key')->all())->toBe(['intro', "client-{$this->manzanas->id}", 'outro'])
        ->and($sections->pluck('kind')->all())->toBe([WeeklyAudioSectionKind::Intro, WeeklyAudioSectionKind::Client, WeeklyAudioSectionKind::Outro])
        ->and($sections[1]->client_id)->toBe($this->manzanas->id)
        ->and($sections[0]->duration_ms)->toBe(3500)
        ->and($sections[0]->path)->toBe("weeklies/{$w39->id}/audio/intro-weeklysync.mp3")
        ->and(Storage::disk('local')->exists($sections[1]->path))->toBeTrue()
        // La del cierre no estaba en el Storage: queda con el guion y sin fichero.
        ->and($sections[2]->path)->toBeNull()
        ->and($sections[2]->script)->toBe('Hasta la semana que viene.')
        ->and($report->filesMissing)->toBe(1)
        ->and($report->filesCopied)->toBe(6);
});

test('importa tareas que casan con un proyecto, avisos, ayuda, sugerencias y uso de IA', function () {
    $activityBefore = Activity::query()->count();
    $report = runWeeklySyncImport();
    $diego = User::query()->where('email', 'diego@antiguo.test')->sole();

    // Tareas: la de Manzanas (un solo proyecto), hecha y archivada para Ana; la de Fresas no.
    $task = Task::query()->sole();
    expect($task->project_id)->toBe($this->manzanasProject->id)
        ->and($task->status->category)->toBe(TaskStatusCategory::Done)
        ->and($task->priority)->toBe(TaskPriority::High)
        ->and($task->assignee_user_id)->toBe($this->ana->id)
        ->and($task->created_by)->toBe($this->bruno->id)
        ->and($task->description)->toContain('<strong>versión 2</strong>')
        ->and($task->completed_at)->not->toBeNull()
        ->and(TaskArchive::query()->where(['user_id' => $this->ana->id, 'task_id' => $task->id])->exists())->toBeTrue()
        ->and($report->warnings())->toHaveKey('Tarea no migrada (Tareas de clientes sin un proyecto claro): «Llamar a Fresas».');

    // Reglas: el domingo de WeeklySync (0) es el 7 ISO; la mal formada no entra.
    expect(WeeklyReminderRule::query()->orderBy('position')->get()->map(fn ($r) => [$r->channel, $r->day_of_week, $r->time, $r->enabled])->all())->toBe([
        [WeeklyReminderChannel::Email, 5, '16:00', true],
        [WeeklyReminderChannel::Push, 7, '10:00', false],
    ]);

    // Plantillas: solo la cambiada en WeeklySync, sin la marca vieja.
    $templates = Setting::get('weekly_email_templates');
    expect(array_keys($templates))->toBe(['manual'])
        ->and($templates['manual']['subject'])->toBe('¡Tu weekly, {nombre}!')
        ->and($templates['manual']['body'])->toContain('en Audax Proyectos');

    // Registro de envíos.
    $w39 = WeeklyCycle::query()->where('number', 'W39-26')->sole();
    $w40 = WeeklyCycle::query()->where('number', 'W40-26')->sole();
    $logs = WeeklyReminderLog::query()->orderBy('created_at')->get();
    expect($logs)->toHaveCount(2)
        ->and([$logs[0]->user_id, $logs[0]->weekly_cycle_id, $logs[0]->status, $logs[0]->recipient_email])->toBe([$this->ana->id, $w39->id, WeeklyReminderStatus::Sent, 'ana@audax.test'])
        ->and($logs[0]->trigger_key)->toStartWith('ws:'.wsId('99999999', 31).':automatic:')
        ->and([$logs[1]->user_id, $logs[1]->weekly_cycle_id, $logs[1]->status, $logs[1]->error])->toBe([$diego->id, $w40->id, WeeklyReminderStatus::Failed, 'Rate limit'])
        ->and($logs[1]->created_at?->toIso8601String())->toBe('2026-10-01T09:00:00+00:00');

    // Ayuda.
    $manual = Setting::get('help_manual');
    expect(Setting::get('help_support_url'))->toBe('https://soporte.example.test')
        ->and($manual['name'])->toBe('Manual de WeeklySync.pdf')
        ->and(Storage::disk('local')->exists($manual['path']))->toBeTrue();

    $release = HelpRelease::query()->where(['major_version' => 1, 'month_number' => 9, 'week_of_month' => 4])->sole();
    expect($release->summary)->toBe('Mejoras del informe')
        ->and(HelpRelease::query()->where(['month_number' => 10, 'week_of_month' => 1])->sole()->is_hidden)->toBeTrue()
        ->and(HelpReleaseChange::query()->where('help_release_id', $release->id)->orderBy('position')->pluck('description')->all())->toBe(['Audio por secciones', 'Arreglos varios'])
        ->and(HelpManualUpdate::query()->sole()->body)->toContain('<strong>Novedad</strong>')->toContain('<li>Primer punto</li>')
        ->and(HelpUpdateLike::query()->count())->toBe(2);

    $tutorial = HelpTutorial::query()->sole();
    expect($tutorial->help_release_id)->toBe($release->id)
        ->and($tutorial->video?->mime)->toBe('video/mp4')
        ->and($tutorial->video?->original_name)->toBe('weekly.mp4')
        ->and($tutorial->video?->path)->toStartWith('help/tutorials/weeklysync-')
        ->and(Storage::disk('local')->exists((string) $tutorial->video?->path))->toBeTrue();

    expect(HelpFaqSection::query()->orderBy('name')->pluck('name')->all())->toBe(['General', 'Primeros pasos'])
        ->and(HelpFaq::query()->where('question', '¿Cuándo se cierra la weekly?')->sole()->answer)->toContain('<strong>viernes</strong>');

    // Sugerencias: el tablero con «bugs» es el precargado de Audax.
    $audaxBoard = SuggestionBoard::query()->where('slug', 'sugerencias')->sole();
    $post = SuggestionPost::query()->sole();
    expect(SuggestionBoard::query()->count())->toBe(2)
        ->and(SuggestionBoard::query()->where('slug', 'ideas-equipo')->sole()->description)->toBe('Procesos internos.')
        ->and($post->suggestion_board_id)->toBe($audaxBoard->id)
        ->and(SuggestionCategory::query()->where(['suggestion_board_id' => $audaxBoard->id, 'slug' => 'bugs'])->count())->toBe(1)
        ->and($post->suggestion_category_id)->toBe(SuggestionCategory::query()->where(['suggestion_board_id' => $audaxBoard->id, 'slug' => 'general'])->value('id'))
        ->and($post->author_id)->toBe($this->bruno->id)
        ->and($post->status)->toBe(SuggestionStatus::Planned)
        ->and($post->body)->toContain('<strong>exportar</strong>')
        ->and($post->body)->toContain(sprintf('<span data-type="mention" data-id="%d" data-label="Ana de Audax">', $this->ana->id))
        ->and(RichText::mentionedUserIds($post->body))->toBe([$this->ana->id])
        ->and(RichText::toPlainText($post->body))->toContain('@Elena')
        ->and($post->vote_count)->toBe(1)
        ->and($post->comment_count)->toBe(2)
        ->and(SuggestionVote::query()->sole()->user_id)->toBe($this->ana->id);

    $parent = SuggestionComment::query()->whereNull('parent_id')->sole();
    expect(SuggestionComment::query()->whereNotNull('parent_id')->sole()->parent_id)->toBe($parent->id)
        ->and($parent->edited_at)->not->toBeNull()
        ->and(SuggestionCommentReaction::query()->sole()->reaction)->toBe(SuggestionReaction::Rocket)
        ->and(SuggestionStatusEvent::query()->orderBy('created_at')->pluck('note')->all())->toBe([null, 'Lo haremos en octubre.']);

    $attachment = Attachment::query()->where('attachable_type', $post->getMorphClass())->sole();
    expect($attachment->mime)->toBe('image/png')
        ->and($attachment->user_id)->toBe($this->bruno->id)
        ->and($attachment->path)->toBe("attachments/suggestions/{$post->id}/weeklysync-".wsId('99999999', 67).'.png');

    // Uso de IA: lo que tiene función en Audax; el OCR no.
    $usage = AiUsage::query()->orderBy('created_at')->get();
    expect($usage)->toHaveCount(2)
        ->and([$usage[0]->feature, $usage[0]->provider, $usage[0]->user_id, $usage[0]->estimated_cost_usd])->toBe([AiFeature::WeeklyReport, AiProvider::Gemini, $this->ana->id, '0.001200'])
        ->and([$usage[1]->feature, $usage[1]->provider, $usage[1]->status])->toBe([AiFeature::Speech, AiProvider::GoogleTts, AiUsage::STATUS_ERROR])
        ->and($report->warnings())->toHaveKey('Coste de IA de WeeklySync que no entra en «Uso de IA» (funciones de OCR que ya no existen): 0.005000 USD.');

    // Una sola entrada de auditoría, sin una por fila.
    expect(Activity::query()->where('event', 'weeklysync_import')->count())->toBe(1)
        ->and(Activity::query()->count())->toBe($activityBefore + 1);
    Notification::assertNothingSent();
});

test('es idempotente: la segunda pasada no crea ni cambia nada', function () {
    runWeeklySyncImport();
    $counts = weeklySyncTableCounts();
    $snapshot = WeeklyEntry::query()->orderBy('id')->get(['id', 'client_id', 'body', 'position'])->toArray();

    $second = runWeeklySyncImport();

    expect(weeklySyncTableCounts())->toBe($counts)
        ->and(WeeklyEntry::query()->orderBy('id')->get(['id', 'client_id', 'body', 'position'])->toArray())->toBe($snapshot)
        ->and($second->filesCopied)->toBe(0)
        ->and($second->filesUnchanged)->toBe(6)
        ->and($second->hasDifferences())->toBeFalse();

    foreach ($second->counts() as $type => $outcomes) {
        expect($outcomes[WeeklySyncImportReport::CREATED] ?? 0)->toBe(0, "{$type} creados")
            ->and($outcomes[WeeklySyncImportReport::UPDATED] ?? 0)->toBe(0, "{$type} actualizados");
    }
});

test('--dry-run lo deshace todo y no copia ficheros', function () {
    $counts = weeklySyncTableCounts();

    $report = runWeeklySyncImport(dryRun: true);

    expect(weeklySyncTableCounts())->toBe($counts)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and($report->dryRun)->toBeTrue()
        ->and($report->get('cycles', WeeklySyncImportReport::CREATED))->toBe(3)
        ->and($report->filesCopied)->toBe(6)
        ->and(Setting::get('weekly_email_templates'))->toBeNull()
        ->and(Activity::query()->where('event', 'weeklysync_import')->exists())->toBeFalse();
});

test('no toca lo escrito en Audax: la semana ya abierta, sus envíos ni su satisfacción', function () {
    $w41 = WeeklyCycle::factory()->active('2026-10-05')->create();
    $own = WeeklySubmission::factory()->submitted(CarbonImmutable::parse('2026-10-06 10:00'))->create(['weekly_cycle_id' => $w41->id, 'user_id' => $this->bruno->id]);
    WeeklyEntry::factory()->general()->create(['weekly_submission_id' => $own->id, 'body' => 'Escrito en Audax']);
    ClientSatisfactionSnapshot::factory()->create(['client_id' => $this->peras->id, 'weekly_cycle_id' => $w41->id, 'score' => 33]);
    $this->peras->update(['satisfaction_score' => 33]);

    $report = runWeeklySyncImport();

    expect(WeeklyCycle::query()->where('number', 'W41-26')->sole()->id)->toBe($w41->id)
        ->and(WeeklyCycle::query()->active()->count())->toBe(1)
        ->and($own->entries()->pluck('body')->all())->toBe(['Escrito en Audax'])
        ->and($report->skipped())->toHaveKey('Envíos que ya existen en Audax (escritos en Audax)')
        ->and($this->peras->fresh()->satisfaction_score)->toBe(33)
        ->and($report->skipped())->toHaveKey('Satisfacción actual que ya calcula Audax')
        // El borrador de Ana sí entra en la semana de Audax.
        ->and(WeeklySubmission::query()->where(['weekly_cycle_id' => $w41->id, 'user_id' => $this->ana->id])->sole()->submitted_at)->toBeNull();
});

test('una semana activa de WeeklySync entra cerrada si Audax ya tiene otra activa', function () {
    WeeklyCycle::factory()->active('2026-10-12')->create();

    $report = runWeeklySyncImport();

    expect(WeeklyCycle::query()->where('number', 'W41-26')->sole()->status)->toBe(WeeklyCycleStatus::Closed)
        ->and(WeeklyCycle::query()->active()->count())->toBe(1)
        ->and($report->warnings())->toHaveKey('La semana W41-26 está activa en WeeklySync, pero Audax ya tiene otra activa: entra cerrada.');
});

test('sin ficheros de correspondencias, lo que no casa se crea inactivo', function () {
    runWeeklySyncImport(mappings: new WeeklySyncMappings);

    // Carla de WeeklySync (otro correo) y Fede: cuentas inactivas; Limones y Naranjas: clientes inactivos.
    expect(User::query()->where('email', 'carla.vieja@gmail.test')->sole()->is_active)->toBeFalse()
        ->and(User::query()->where('email', 'fede@pruebas.test')->sole()->is_active)->toBeFalse()
        ->and(Client::query()->whereIn('name', ['Limones', 'Naranjas'])->where('is_active', false)->count())->toBe(2);
});

test('al corregir las correspondencias, la siguiente pasada mueve lo importado', function () {
    runWeeklySyncImport(mappings: new WeeklySyncMappings);
    $limones = Client::query()->where('name', 'Limones')->sole();
    expect(WeeklyEntry::query()->where('client_id', $limones->id)->count())->toBe(1);

    runWeeklySyncImport();

    expect(WeeklyEntry::query()->where('client_id', $limones->id)->count())->toBe(0)
        ->and(WeeklyEntry::query()->where('client_id', $this->citricos->id)->sole()->body)->toBe("Limones: arranque.\n\nNaranjas: presupuesto.");
});

test('un volcado que no cuadra con su manifiesto no se importa', function () {
    $directory = storage_path('framework/testing/weeklysync-'.uniqid());
    File::copyDirectory(base_path(WS_FIXTURE), $directory);
    file_put_contents($directory.'/tables/weekly_submissions.json', "[]\n");
    $counts = weeklySyncTableCounts();

    expect(fn () => runWeeklySyncImport(directory: $directory))->toThrow(RuntimeException::class, 'no cuadra con el manifiesto');
    expect(weeklySyncTableCounts())->toBe($counts);

    File::deleteDirectory($directory);
});

test('el comando muestra el informe y valida los ficheros de correspondencias', function () {
    $this->artisan('app:import-weeklysync', ['volcado' => base_path(WS_FIXTURE), '--dry-run' => true])
        ->expectsOutputToContain('Simulación de la importación de WeeklySync')
        ->expectsOutputToContain('Cuadra')
        ->expectsOutputToContain('Persona sin cuenta en Audax, creada inactiva')
        ->assertSuccessful();

    $bad = storage_path('framework/testing/personas-'.uniqid().'.json');
    file_put_contents($bad, json_encode(['people' => [['weeklysync_email' => 'x@y.test']]]));

    $this->artisan('app:import-weeklysync', ['volcado' => base_path(WS_FIXTURE), '--personas' => $bad])
        ->expectsOutputToContain('no es válido')
        ->assertFailed();

    expect(WeeklyCycle::query()->count())->toBe(0);
    @unlink($bad);
});
