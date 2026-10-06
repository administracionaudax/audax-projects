<?php

use App\Domain\Weeklies\Ai\AiQueue;
use App\Domain\Weeklies\Ai\FakeLlm;
use App\Domain\Weeklies\Ai\FakeSpeechSynthesizer;
use App\Domain\Weeklies\Ai\GoogleTtsSynthesizer;
use App\Domain\Weeklies\Ai\LlmNotConfigured;
use App\Domain\Weeklies\Ai\LlmRequest;
use App\Domain\Weeklies\Ai\LlmUnavailable;
use App\Domain\Weeklies\Audio\Mp3Duration;
use App\Domain\Weeklies\Audio\WeeklyAudioGenerator;
use App\Domain\Weeklies\Audio\WeeklyAudioScripts;
use App\Domain\Weeklies\Report\WeeklyReport;
use App\Enums\AiFeature;
use App\Enums\WeeklyAudioSectionKind;
use App\Enums\WeeklyJobState;
use App\Http\Resources\Weeklies\WeeklyAudioSectionResource;
use App\Jobs\GenerateWeeklyAudio;
use App\Models\Client;
use App\Models\User;
use App\Models\WeeklyAudioSection;
use App\Models\WeeklyCycle;
use App\Models\WeeklyEntry;
use App\Models\WeeklySubmission;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| El audio del informe (10.3, F-084 a F-087, D-190): guion por secciones con Gemini (FakeLlm) y
| locución con el doble de TTS, ficheros en el disco privado, URL firmada por sección, el audio
| completo y la descarga.
*/

beforeEach(function () {
    Storage::fake('local');
    $this->manager = userWithRole('admin');
    $this->acme = Client::factory()->create(['name' => 'Acme']);
    $this->cycle = WeeklyCycle::factory()->active()->create();
    $this->cycle->forceFill([
        'report' => [
            'global_summary' => 'Buena semana.',
            'team_risks' => ['Plazo ajustado'],
            'client_updates' => [
                ['client_id' => $this->acme->id, 'client_name' => 'Acme', 'status' => 'risk', 'executive_summary' => 'Entregada la home.', 'next_steps' => ['Validar'], 'milestones' => [['date' => '12/10', 'label' => 'Lanzamiento']], 'tags' => [], 'has_reports' => true,
                    'projects' => [['project_id' => 1, 'code' => 'ACME-BH1', 'name' => 'Bolsa', 'billing_type' => 'hour_bank', 'budget_minutes' => 1200, 'consumed_minutes' => 750, 'expected_minutes' => null, 'week_minutes' => 120]]],
                ['client_id' => null, 'client_name' => 'General / Interno', 'status' => 'on_track', 'executive_summary' => 'Formación.', 'next_steps' => [], 'milestones' => [], 'tags' => [], 'has_reports' => true, 'projects' => []],
            ],
        ],
        'report_text' => "# Semana\n\nTexto",
    ])->save();
    $submission = WeeklySubmission::factory()->submitted()->create(['weekly_cycle_id' => $this->cycle->id, 'user_id' => userWithRole('employee', ['name' => 'Elena'])->id]);
    WeeklyEntry::factory()->create(['weekly_submission_id' => $submission->id, 'client_id' => $this->acme->id, 'body' => 'Subida la home a producción.']);
});

/** La respuesta del guion: un bloque por cliente con su clave. */
function audioNarration(array $clients = ['client-%d' => 'Acme fue bien.', 'client-general' => 'Formación interna.']): array
{
    return [
        'intro' => 'Hola equipo.',
        'clients' => array_map(fn (string $key, string $script): array => ['clientKey' => sprintf($key, test()->acme->id), 'script' => $script], array_keys($clients), $clients),
        'outro' => 'Hasta la semana que viene.',
    ];
}

it('el guion lleva una sección por cliente, con sus reportes originales y el estado de sus proyectos', function () {
    $llm = FakeLlm::bind()->push(audioNarration());

    $sections = app(WeeklyAudioScripts::class)->build($this->cycle, $this->cycle->reportData(), $this->cycle->report_text);

    expect(array_column($sections, 'key'))->toBe(['intro', "client-{$this->acme->id}", 'client-general', 'outro'])
        ->and(array_map(fn (array $s) => $s['kind'], $sections))->toBe([WeeklyAudioSectionKind::Intro, WeeklyAudioSectionKind::Client, WeeklyAudioSectionKind::Client, WeeklyAudioSectionKind::Outro])
        ->and($sections[1]['client_id'])->toBe($this->acme->id)
        ->and($sections[1]['script'])->toBe('Acme fue bien.')
        ->and($sections[3]['script'])->toBe('Hasta la semana que viene.');

    $llm->assertSent(fn (LlmRequest $r) => $r->feature === AiFeature::AudioScript
        && str_starts_with($r->prompt, 'Eres guionista de locuciones internas para Audax Studio.')
        && str_contains($r->prompt, 'Resumen global: Buena semana.')
        && str_contains($r->prompt, 'Riesgos de equipo: Plazo ajustado')
        && str_contains($r->prompt, '"clientKey": "client-'.$this->acme->id.'"')
        && str_contains($r->prompt, '"content": "Subida la home a producción."')
        && str_contains($r->prompt, '"projectStatusText": "ACME-BH1 (Bolsa de horas): 12.5h consumidas de 20h"')
        && str_contains($r->prompt, '"status": "Risk"'));
});

it('lo que la IA no devuelve se completa con el texto del original, y una sección en inglés se traduce', function () {
    FakeLlm::bind()->push(
        ['intro' => '', 'clients' => [['clientKey' => 'client-general', 'script' => 'This week the team was working on the design and the website for this client.']], 'outro' => ''],
        'Esta semana el equipo trabajó en el diseño y la web.',
    );

    $sections = app(WeeklyAudioScripts::class)->build($this->cycle, $this->cycle->reportData(), $this->cycle->report_text);

    expect($sections[0]['script'])->toBe('Hola equipo. Vamos con el resumen semanal. Buena semana.')
        ->and($sections[1]['script'])->toBe('En cuanto a Acme, Entregada la home. En los reportes del equipo se mencionó lo siguiente: Subida la home a producción. Próximos pasos: Validar. Próximos hitos: 12/10: Lanzamiento. Estado de proyectos: ACME-BH1 (Bolsa de horas): 12.5h consumidas de 20h')
        ->and($sections[2]['script'])->toBe('Esta semana el equipo trabajó en el diseño y la web.')
        ->and($sections[3]['script'])->toBe('Como puntos de atención del equipo, tenemos: Plazo ajustado. Y con esto cerramos el repaso de la semana.');
});

it('si la IA no responde, todo el guion sale sin IA; sin clave, no sigue', function () {
    FakeLlm::bind()->push(new LlmUnavailable('caída'));
    $sections = app(WeeklyAudioScripts::class)->build($this->cycle, $this->cycle->reportData(), $this->cycle->report_text);

    expect(array_column($sections, 'key'))->toBe(['intro', "client-{$this->acme->id}", 'client-general', 'outro'])
        ->and($sections[2]['script'])->toBe('En cuanto a General / Interno, Formación.');

    FakeLlm::bind()->push(new LlmNotConfigured('sin clave'));
    expect(fn () => app(WeeklyAudioScripts::class)->build($this->cycle, $this->cycle->reportData(), $this->cycle->report_text))->toThrow(LlmNotConfigured::class);
});

it('sin clientes, una sola sección con el informe narrado', function () {
    $report = new WeeklyReport('Nada que contar.');
    FakeLlm::bind()->push('Narración completa.');

    $sections = app(WeeklyAudioScripts::class)->build($this->cycle, $report, 'Texto');

    expect($sections)->toHaveCount(1)
        ->and($sections[0]['key'])->toBe('full-weekly')
        ->and($sections[0]['script'])->toBe('Narración completa.');
});

it('locuta cada sección, guarda los MP3 en el disco privado y el audio completo unido', function () {
    FakeLlm::bind()->push(audioNarration());
    $speech = FakeSpeechSynthesizer::bind();

    (new GenerateWeeklyAudio($this->cycle->id, $this->manager->id))->handle(app(WeeklyAudioGenerator::class));
    $cycle = $this->cycle->refresh();
    $sections = $cycle->audioSections;

    expect($cycle->audio_state)->toBe(WeeklyJobState::Done)
        ->and($cycle->audio_generated_by)->toBe($this->manager->id)
        ->and($sections)->toHaveCount(4)
        ->and($sections->pluck('key')->all())->toBe(['intro', "client-{$this->acme->id}", 'client-general', 'outro'])
        ->and($sections[1]->client_id)->toBe($this->acme->id)
        ->and($sections[1]->voice)->toBe('fake-voice')
        ->and(count($speech->requests))->toBe(4)
        ->and($speech->requests[1]->text)->toBe('Acme fue bien.')
        ->and($speech->requests[1]->feature)->toBe(AiFeature::Speech);

    Storage::disk('local')->assertExists($cycle->audio_path);
    foreach ($sections as $section) {
        Storage::disk('local')->assertExists($section->path);
    }
    expect(Storage::disk('local')->get($cycle->audio_path))->toBe(GoogleTtsSynthesizer::mergeMp3($sections->map(fn ($s) => Storage::disk('local')->get($s->path))->all()));

    // Regenerar sustituye las secciones y borra los MP3 anteriores.
    $old = [$cycle->audio_path, ...$sections->pluck('path')->all()];
    FakeLlm::bind()->push(audioNarration());
    (new GenerateWeeklyAudio($cycle->id))->handle(app(WeeklyAudioGenerator::class));
    foreach ($old as $path) {
        Storage::disk('local')->assertMissing($path);
    }
    expect(WeeklyAudioSection::query()->where('weekly_cycle_id', $cycle->id)->count())->toBe(4);
});

it('si falla la locución, el audio anterior se conserva y el error dice que es la locución', function () {
    FakeLlm::bind()->push(audioNarration());
    FakeSpeechSynthesizer::bind();
    (new GenerateWeeklyAudio($this->cycle->id))->handle(app(WeeklyAudioGenerator::class));
    $before = $this->cycle->refresh()->audio_path;

    FakeLlm::bind()->push(audioNarration());
    FakeSpeechSynthesizer::bind()->failWith(new LlmNotConfigured('GOOGLE_TTS_API_KEY no está configurada.'));
    (new GenerateWeeklyAudio($this->cycle->id))->handle(app(WeeklyAudioGenerator::class));
    $cycle = $this->cycle->refresh();

    expect($cycle->audio_state)->toBe(WeeklyJobState::Failed)
        ->and($cycle->audio_error)->toBe('La locución no está configurada (falta GOOGLE_TTS_API_KEY).')
        ->and($cycle->audio_path)->toBe($before)
        ->and($cycle->audioSections)->toHaveCount(4);
    Storage::disk('local')->assertExists($before);
    expect(Storage::disk('local')->allFiles("weeklies/{$cycle->id}/audio"))->toHaveCount(5);
});

it('el audio necesita el texto; se encola en la cola prioritaria ai-high y no dos a la vez', function () {
    Queue::fake();
    $empty = WeeklyCycle::factory()->create();

    $this->actingAs($this->manager)->postJson("/weeklies/{$empty->id}/audio")->assertJsonValidationErrors(['audio' => 'Primero genera el texto del informe.']);
    $this->actingAs($this->manager)->post("/weeklies/{$this->cycle->id}/audio")->assertRedirect();
    Queue::assertPushedOn(AiQueue::HIGH, GenerateWeeklyAudio::class);
    $this->actingAs($this->manager)->postJson("/weeklies/{$this->cycle->id}/audio")->assertJsonValidationErrors(['audio' => 'El audio ya se está generando.']);
    $this->actingAs(userWithRole('employee'))->postJson("/weeklies/{$this->cycle->id}/audio")->assertForbidden();
});

it('cada sección se sirve con URL firmada y permiso; el audio completo, en línea o como descarga', function () {
    FakeLlm::bind()->push(audioNarration());
    FakeSpeechSynthesizer::bind();
    (new GenerateWeeklyAudio($this->cycle->id))->handle(app(WeeklyAudioGenerator::class));
    $section = $this->cycle->audioSections()->firstOrFail();
    $url = WeeklyAudioSectionResource::make($section)->resolve()['url'];
    $employee = userWithRole('employee');

    expect($url)->toStartWith("/weeklies/{$this->cycle->id}/audio/{$section->id}?expires=");
    $this->actingAs($employee)->get($url)->assertOk()->assertHeader('Content-Type', 'audio/mpeg')->assertHeader('Accept-Ranges', 'bytes');
    $this->actingAs($employee)->get("/weeklies/{$this->cycle->id}/audio/{$section->id}")->assertForbidden();
    $this->actingAs(User::factory()->collaborator()->create())->get($url)->assertForbidden();

    $inline = $this->actingAs($employee)->get("/weeklies/{$this->cycle->id}/audio");
    $inline->assertOk();
    expect($inline->headers->get('Content-Disposition'))->toStartWith('inline;');

    $download = $this->actingAs($employee)->get("/weeklies/{$this->cycle->id}/audio?descargar=1");
    expect($download->headers->get('Content-Disposition'))->toContain('attachment;')->toContain('Weekly-W41-26-Audio.mp3');

    $this->actingAs($employee)->get('/weeklies/'.WeeklyCycle::factory()->create()->id.'/audio')->assertNotFound();
});

it('la página del informe trae las secciones con su URL firmada', function () {
    FakeLlm::bind()->push(audioNarration());
    FakeSpeechSynthesizer::bind();
    (new GenerateWeeklyAudio($this->cycle->id))->handle(app(WeeklyAudioGenerator::class));

    $this->actingAs(userWithRole('employee'))->get("/weeklies/{$this->cycle->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('cycle.audio_sections', 4)
            ->where('cycle.has_full_audio', true)
            ->where('cycle.audio_sections.1.key', "client-{$this->acme->id}")
            ->where('cycle.audio_sections.1.client_id', $this->acme->id)
            ->where('cycle.audio_sections.1.url', fn (string $url) => str_contains($url, 'signature=')));
});

it('calcula la duración de un MP3 por sus tramas y no se inventa la de lo que no lo es', function () {
    // 10 tramas MPEG-1 capa III a 128 kbps y 44,1 kHz: 417 bytes cada una y 1152 muestras.
    $frame = "\xFF\xFB\x90\x00".str_repeat("\0", 413);
    $id3 = 'ID3'."\x04\x00\x00\x00\x00\x00\x0A".str_repeat("\0", 10);

    expect(Mp3Duration::milliseconds($id3.str_repeat($frame, 10)))->toBe(261)
        ->and(Mp3Duration::milliseconds('ID3FAKE-MP3:abc'))->toBeNull()
        ->and(Mp3Duration::milliseconds(''))->toBeNull();
});
