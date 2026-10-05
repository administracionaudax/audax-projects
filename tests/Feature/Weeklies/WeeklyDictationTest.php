<?php

use App\Domain\Chat\Transcription\FakeTranscriber;
use App\Domain\Chat\Transcription\TranscriptionFailed;
use App\Domain\Chat\Transcription\TranscriptionService;
use App\Domain\Weeklies\Ai\AiQueue;
use App\Domain\Weeklies\Ai\FakeLlm;
use App\Domain\Weeklies\Ai\LlmRequest;
use App\Domain\Weeklies\Ai\LlmUnavailable;
use App\Domain\Weeklies\Dictation\DictationCleaner;
use App\Enums\AiFeature;
use App\Enums\DictationContext;
use App\Enums\TranscriptionStatus;
use App\Events\Weeklies\DictationUpdated;
use App\Jobs\CleanDictation;
use App\Jobs\TranscribeDictation;
use App\Models\Client;
use App\Models\Dictation;
use App\Models\Setting;
use App\Models\User;
use App\Models\WeeklyCycle;
use Carbon\CarbonImmutable;
use Database\Seeders\DefaultSettingsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
| Dictado de la weekly (10.2, D-146, D-152 y D-158; F-049, F-050, F-171 y F-172): subir el audio, el
| Job que lo transcribe con el Whisper del servidor (aquí FakeTranscriber), la limpieza opcional con
| IA (FakeLlm) y el aviso en tiempo real. El audio nunca se guarda: se borra al transcribir.
*/

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Europe/Madrid'));
    $this->cycle = WeeklyCycle::factory()->active('2026-10-05')->create();
    $this->me = userWithRole('employee', ['created_at' => '2026-09-01 08:00:00']);
    $this->engine = new FakeTranscriber('Hoy he cerrado la propuesta de Acme y mañana sigo con la web.');
    $this->app->instance(TranscriptionService::class, $this->engine);
});

function dictationUpload(array $overrides = []): array
{
    return [
        'context' => 'weekly_entry',
        'weekly_cycle_id' => test()->cycle->id,
        'audio' => UploadedFile::fake()->create('dictado.webm', 40, 'audio/webm'),
        'duration_ms' => 12_000,
        ...$overrides,
    ];
}

function storedDictation(User $user, string $path = 'dictations/x.webm', array $attributes = []): Dictation
{
    Storage::disk('local')->put($path, 'audio');

    return Dictation::query()->create([
        'user_id' => $user->id,
        'context' => DictationContext::WeeklyEntry,
        'weekly_cycle_id' => test()->cycle->id,
        'disk' => 'local',
        'path' => $path,
        'audio_duration_ms' => 12_000,
        ...$attributes,
    ]);
}

// --- Subir el audio ----------------------------------------------------------------------------

it('sube el audio, lo guarda en privado y lo encola en «transcriptions»', function () {
    Queue::fake();
    $client = Client::factory()->create();

    $response = $this->actingAs($this->me)
        ->post('/mi-espacio/dictados', dictationUpload(['client_id' => $client->id]), ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('dictation.status', 'pending')
        ->assertJsonPath('dictation.client_id', $client->id)
        ->assertJsonPath('dictation.text', null);

    $dictation = Dictation::query()->findOrFail($response->json('dictation.id'));

    expect($dictation->user_id)->toBe($this->me->id)
        ->and($dictation->weekly_cycle_id)->toBe($this->cycle->id)
        ->and($dictation->path)->toStartWith("dictations/{$this->me->id}/");
    Storage::disk('local')->assertExists((string) $dictation->path);
    Queue::assertPushedOn('transcriptions', TranscribeDictation::class, fn (TranscribeDictation $job) => $job->dictationId === $dictation->id);
});

it('un audio demasiado corto no se transcribe: queda hecho, sin texto y con aviso (F-050)', function (array $overrides) {
    Queue::fake();

    $this->actingAs($this->me)
        ->post('/mi-espacio/dictados', dictationUpload($overrides), ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('dictation.status', 'done')
        ->assertJsonPath('dictation.text', '')
        ->assertJsonPath('dictation.warning', 'too_short');

    Queue::assertNothingPushed();
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'menos de 0,7 s' => [['duration_ms' => 600]],
    'menos de 1,5 KB' => [['audio' => UploadedFile::fake()->create('dictado.webm', 1, 'audio/webm')]],
]);

it('valida el audio, la duración, el contexto y la semana', function (Closure $overrides, string $error) {
    $this->actingAs($this->me)
        ->postJson('/mi-espacio/dictados', dictationUpload($overrides()))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$error]);
})->with([
    'no es audio' => [fn () => ['audio' => UploadedFile::fake()->create('dictado.pdf', 40, 'application/pdf')], 'audio'],
    'extensión falsa' => [fn () => ['audio' => UploadedFile::fake()->create('dictado.webm', 40, 'application/pdf')], 'audio'],
    'sin duración' => [fn () => ['duration_ms' => null], 'duration_ms'],
    'demasiado largo' => [fn () => ['duration_ms' => 3_600_000], 'duration_ms'],
    'tamaño imposible' => [fn () => ['audio' => UploadedFile::fake()->create('dictado.webm', 5000, 'audio/webm'), 'duration_ms' => 2000], 'audio'],
    'contexto de tareas (10.6)' => [fn () => ['context' => 'task_note'], 'context'],
    'semana cerrada' => [fn () => ['weekly_cycle_id' => WeeklyCycle::factory()->create()->id], 'weekly_cycle_id'],
    'semana inexistente' => [fn () => ['weekly_cycle_id' => 999999], 'weekly_cycle_id'],
    'cliente borrado' => [fn () => ['client_id' => tap(Client::factory()->create())->delete()->id], 'client_id'],
]);

it('un colaborador externo no dicta; cada uno ve solo sus dictados', function () {
    $this->actingAs(User::factory()->collaborator()->create())->postJson('/mi-espacio/dictados', dictationUpload())->assertForbidden();

    $mine = storedDictation($this->me);
    $other = userWithRole('employee');

    $this->actingAs($this->me)->getJson("/mi-espacio/dictados/{$mine->id}")->assertOk()->assertJsonPath('dictation.id', $mine->id);
    $this->actingAs($other)->getJson("/mi-espacio/dictados/{$mine->id}")->assertForbidden();
    $this->actingAs(userWithRole('admin'))->getJson("/mi-espacio/dictados/{$mine->id}")->assertForbidden();
});

it('de punta a punta (cola síncrona): subir, transcribir y consultar el texto', function () {
    $response = $this->actingAs($this->me)
        ->post('/mi-espacio/dictados', dictationUpload(), ['Accept' => 'application/json'])
        ->assertCreated();

    $this->actingAs($this->me)
        ->getJson('/mi-espacio/dictados/'.$response->json('dictation.id'))
        ->assertJsonPath('dictation.status', 'done')
        ->assertJsonPath('dictation.text', 'Hoy he cerrado la propuesta de Acme y mañana sigo con la web.')
        ->assertJsonPath('dictation.warning', null);

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

// --- El Job de transcripción -------------------------------------------------------------------

it('transcribe con Whisper, guarda el texto, borra el audio y avisa por tiempo real', function () {
    Event::fake([DictationUpdated::class]);
    $dictation = storedDictation($this->me);

    TranscribeDictation::dispatchSync($dictation->id);

    $dictation->refresh();

    expect($dictation->status)->toBe(TranscriptionStatus::Done)
        ->and($dictation->text)->toBe('Hoy he cerrado la propuesta de Acme y mañana sigo con la web.')
        ->and($dictation->raw_text)->toBe('Hoy he cerrado la propuesta de Acme y mañana sigo con la web.')
        ->and($dictation->engine)->toBe('fake')
        ->and($dictation->attempts)->toBe(1)
        ->and($dictation->transcribed_at)->not->toBeNull()
        ->and($dictation->path)->toBeNull()
        ->and($this->engine->calls)->toBe(1);
    Storage::disk('local')->assertMissing('dictations/x.webm');
    Event::assertDispatched(DictationUpdated::class, fn (DictationUpdated $event) => $event->dictationId === $dictation->id
        && $event->status === 'done'
        && $event->broadcastOn()[0]->name === "private-App.Models.User.{$this->me->id}"
        && $event->broadcastAs() === 'dictation.updated');
});

it('sin voz útil (silencio, muletillas o alucinaciones de Whisper): hecho, sin texto y con aviso (F-171)', function (string $heard) {
    $this->engine->respondWith($heard);
    $dictation = storedDictation($this->me);

    TranscribeDictation::dispatchSync($dictation->id);

    expect($dictation->refresh()->status)->toBe(TranscriptionStatus::Done)
        ->and($dictation->text)->toBe('')
        ->and($dictation->warning)->toBe('no_speech')
        ->and($dictation->path)->toBeNull();
})->with([
    'vacío' => [''],
    'anotación' => ['[Música]'],
    'alucinación' => ['Subtítulos realizados por la comunidad de Amara.org'],
    'gracias por ver' => ['¡Gracias por ver el vídeo!'],
    'muletillas' => ['Eh... mmm... vale.'],
    'palabra suelta' => ['Sí.'],
]);

it('si el audio ya no está, el dictado falla sin llamar al motor', function () {
    $dictation = storedDictation($this->me);
    Storage::disk('local')->delete('dictations/x.webm');

    TranscribeDictation::dispatchSync($dictation->id);

    expect($dictation->refresh()->status)->toBe(TranscriptionStatus::Failed)
        ->and($dictation->last_error)->toBe('audio_missing')
        ->and($this->engine->calls)->toBe(0);
});

it('si el motor falla, se reintenta; agotados los intentos queda fallido y sin audio', function () {
    Event::fake([DictationUpdated::class]);
    $this->engine->respondWith(new TranscriptionFailed('whisper caído'));
    $dictation = storedDictation($this->me);
    $job = new TranscribeDictation($dictation->id);

    expect(fn () => $job->handle($this->engine))->toThrow(TranscriptionFailed::class);
    expect($dictation->refresh()->status)->toBe(TranscriptionStatus::Processing)
        ->and($dictation->last_error)->toBe('whisper caído');
    Storage::disk('local')->assertExists('dictations/x.webm');

    $job->failed(new TranscriptionFailed('whisper caído'));

    expect($dictation->refresh()->status)->toBe(TranscriptionStatus::Failed)
        ->and($dictation->path)->toBeNull();
    Storage::disk('local')->assertMissing('dictations/x.webm');
    Event::assertDispatched(DictationUpdated::class, fn (DictationUpdated $event) => $event->status === 'failed');
});

it('un dictado ya hecho no se vuelve a transcribir', function () {
    $dictation = storedDictation($this->me, attributes: ['status' => TranscriptionStatus::Done, 'text' => 'Ya']);

    TranscribeDictation::dispatchSync($dictation->id);

    expect($this->engine->calls)->toBe(0)
        ->and($dictation->refresh()->text)->toBe('Ya');
});

// --- Limpieza con IA (F-172), detrás del ajuste weekly_dictation_cleanup -----------------------

it('con la limpieza apagada (por defecto) no se llama a la IA', function () {
    $llm = FakeLlm::bind();
    $dictation = storedDictation($this->me);

    TranscribeDictation::dispatchSync($dictation->id);

    $llm->assertNothingSent();
    expect(Setting::get(DictationCleaner::SETTING))->toBeFalse();
});

it('con la limpieza encendida, la IA corrige los nombres con el catálogo; solo va el texto', function () {
    Setting::set(DictationCleaner::SETTING, true);
    Client::factory()->create(['name' => 'Acme Corporación']);
    Client::factory()->create(['name' => 'Cliente Inactivo', 'is_active' => false]);
    $llm = FakeLlm::bind()->push('Hoy he cerrado la propuesta de Acme Corporación y mañana sigo con la web.');
    Queue::fake();
    $dictation = storedDictation($this->me);

    (new TranscribeDictation($dictation->id))->handle($this->engine);

    expect($dictation->refresh()->status)->toBe(TranscriptionStatus::Processing)
        ->and($dictation->text)->toBeNull()
        ->and($dictation->path)->toBeNull();
    Queue::assertPushedOn(AiQueue::NAME, CleanDictation::class);

    Event::fake([DictationUpdated::class]);
    (new CleanDictation($dictation->id))->handle(app(DictationCleaner::class));

    expect($dictation->refresh()->status)->toBe(TranscriptionStatus::Done)
        ->and($dictation->text)->toBe('Hoy he cerrado la propuesta de Acme Corporación y mañana sigo con la web.')
        ->and($dictation->raw_text)->toBe('Hoy he cerrado la propuesta de Acme y mañana sigo con la web.');
    $llm->assertSent(fn (LlmRequest $request) => $request->feature === AiFeature::TranscriptCleanup
        && str_contains($request->prompt, 'Acme Corporación')
        && ! str_contains($request->prompt, 'Cliente Inactivo')
        && str_contains($request->prompt, $this->me->name)
        && $request->user?->id === $this->me->id);
    Event::assertDispatched(DictationUpdated::class, fn (DictationUpdated $event) => $event->status === 'done');
});

it('si la IA falla o devuelve algo sin contenido, se queda la transcripción literal', function (mixed $reply) {
    Setting::set(DictationCleaner::SETTING, true);
    FakeLlm::bind()->push($reply);
    $dictation = storedDictation($this->me);

    TranscribeDictation::dispatchSync($dictation->id);

    expect($dictation->refresh()->status)->toBe(TranscriptionStatus::Done)
        ->and($dictation->text)->toBe('Hoy he cerrado la propuesta de Acme y mañana sigo con la web.');
})->with([
    'no responde' => [fn () => new LlmUnavailable('caída')],
    'vacío' => [''],
    'vallas de código vacías' => ["```\n```"],
]);

it('la IA quita las vallas de código de su respuesta', function () {
    Setting::set(DictationCleaner::SETTING, true);
    FakeLlm::bind()->push("```text\nHoy cerré la propuesta de Acme; mañana, la web.\n```");
    $dictation = storedDictation($this->me);

    TranscribeDictation::dispatchSync($dictation->id);

    expect($dictation->refresh()->text)->toBe('Hoy cerré la propuesta de Acme; mañana, la web.');
});

it('un texto muy corto no pasa por la IA aunque la limpieza esté encendida', function () {
    Setting::set(DictationCleaner::SETTING, true);
    $llm = FakeLlm::bind();
    $this->engine->respondWith('Reunión con Acme hoy.');
    $dictation = storedDictation($this->me);

    TranscribeDictation::dispatchSync($dictation->id);

    $llm->assertNothingSent();
    expect($dictation->refresh()->text)->toBe('Reunión con Acme hoy.');
});

it('el ajuste de la limpieza se guarda en /admin/ajustes', function () {
    $this->seed(DefaultSettingsSeeder::class);

    $this->actingAs(userWithRole('admin'))
        ->put('/admin/ajustes', [
            'company_name' => 'Audax Studio',
            'require_2fa' => false,
            'timer_rounding_minutes' => 1,
            'timer_warning_hours' => 10,
            'hour_bank_alert_thresholds' => [75, 90, 100],
            'allow_hour_bank_overage' => true,
            'require_timesheet_approval' => true,
            'allow_future_time_entries' => false,
            'time_entry_description_required' => false,
            'max_attachment_mb' => 50,
            'default_work_minutes' => [480, 480, 480, 480, 480, 0, 0],
            'weekly_digest_enabled' => true,
            'occupancy_low_threshold' => 70,
            'occupancy_high_threshold' => 110,
            'weekly_dictation_cleanup' => true,
        ])
        ->assertSessionHasNoErrors();

    expect(Setting::get(DictationCleaner::SETTING))->toBeTrue();
});
