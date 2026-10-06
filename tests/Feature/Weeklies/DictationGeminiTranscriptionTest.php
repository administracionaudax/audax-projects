<?php

use App\Domain\Chat\Transcription\FakeTranscriber;
use App\Domain\Chat\Transcription\TranscriptionService;
use App\Domain\Weeklies\Ai\AiQueue;
use App\Domain\Weeklies\Ai\AiUsageRecorder;
use App\Domain\Weeklies\Ai\FakeLlm;
use App\Domain\Weeklies\Ai\GeminiClient;
use App\Domain\Weeklies\Ai\LlmClient;
use App\Domain\Weeklies\Ai\LlmUnavailable;
use App\Domain\Weeklies\Dictation\GeminiDictationTranscriber;
use App\Enums\AiFeature;
use App\Enums\DictationContext;
use App\Enums\TranscriptionStatus;
use App\Jobs\CleanDictation;
use App\Jobs\TranscribeDictation;
use App\Models\AiUsage;
use App\Models\Dictation;
use App\Models\Setting;
use App\Models\User;
use App\Models\WeeklyCycle;
use Carbon\CarbonImmutable;
use Database\Seeders\DefaultSettingsSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/*
| Transcripción del dictado de la weekly con Gemini (D-243), como WeeklySync: en segundos y en la
| cola `ai-high`, en lugar de Whisper en la CPU del servidor. Los audios del chat siguen con Whisper.
*/

beforeEach(function () {
    Storage::fake('local');
    Sleep::fake();
    config(['services.transcription.dictation_driver' => 'gemini', 'services.gemini.driver' => 'fake']);
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Europe/Madrid'));
    $this->cycle = WeeklyCycle::factory()->active('2026-10-05')->create();
    $this->me = userWithRole('employee', ['created_at' => '2026-09-01 08:00:00']);
    $this->whisper = new FakeTranscriber('lo que diría whisper');
    $this->app->instance(TranscriptionService::class, $this->whisper);
});

function geminiDictation(User $user, array $attributes = []): Dictation
{
    Storage::disk('local')->put('dictations/g.webm', 'audio-opus');

    return Dictation::query()->create([
        'user_id' => $user->id,
        'context' => DictationContext::WeeklyEntry,
        'weekly_cycle_id' => test()->cycle->id,
        'disk' => 'local',
        'path' => 'dictations/g.webm',
        'mime' => 'audio/webm;codecs=opus',
        'audio_duration_ms' => 7_000,
        ...$attributes,
    ]);
}

it('encola el dictado en «ai-high» con Gemini y no en la cola de Whisper', function () {
    Queue::fake();

    $id = $this->actingAs($this->me)
        ->post('/mi-espacio/dictados', [
            'context' => 'weekly_entry',
            'weekly_cycle_id' => $this->cycle->id,
            'audio' => UploadedFile::fake()->create('dictado.webm', 40, 'audio/webm'),
            'duration_ms' => 7_000,
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->json('dictation.id');

    Queue::assertPushedOn(AiQueue::HIGH, TranscribeDictation::class, fn (TranscribeDictation $job) => $job->dictationId === $id
        && $job->viaGemini && $job->timeout === AiQueue::TIMEOUT);
    Queue::assertNotPushed(TranscribeDictation::class, fn (TranscribeDictation $job) => $job->queue === 'transcriptions');
});

it('sin clave de Gemini, o con el ajuste en whisper, sigue con Whisper en «transcriptions»', function (array $config) {
    config($config);

    $job = new TranscribeDictation(1);

    expect($job->viaGemini)->toBeFalse()
        ->and($job->queue)->toBe('transcriptions');
})->with([
    'sin clave' => [['services.gemini.driver' => 'gemini', 'services.gemini.key' => '']],
    'ajuste whisper' => [['services.transcription.dictation_driver' => 'whisper']],
]);

it('transcribe con Gemini, manda el audio y el prompt de WeeklySync y pasa a la limpieza', function () {
    Queue::fake([CleanDictation::class]);
    $llm = FakeLlm::bind()->push(['hasMeaningfulSpeech' => true, 'transcription' => 'Hoy he cerrado la propuesta de Acme.', 'reason' => 'OK']);
    $dictation = geminiDictation($this->me);

    (new TranscribeDictation($dictation->id))->handle($this->whisper, app(GeminiDictationTranscriber::class));

    $request = $llm->requests()[0];
    expect($request->feature)->toBe(AiFeature::DictationTranscription)
        ->and($request->wantsJson())->toBeTrue()
        ->and($request->prompt)->toContain('Eres un transcriptor de audio extremadamente conservador.')
        ->and($request->audio?->mimeType)->toBe('audio/webm')
        ->and($request->audio?->bytes)->toBe('audio-opus')
        ->and($this->whisper->calls)->toBe(0);

    $dictation->refresh();
    expect($dictation->raw_text)->toBe('Hoy he cerrado la propuesta de Acme.')
        ->and($dictation->engine)->toBe('gemini')
        ->and($dictation->status)->toBe(TranscriptionStatus::Processing)
        ->and($dictation->path)->toBeNull();
    Storage::disk('local')->assertMissing('dictations/g.webm');
    Queue::assertPushed(CleanDictation::class);
});

it('si Gemini dice que no hay voz útil, queda hecho, vacío y con el aviso', function () {
    $this->seed(DefaultSettingsSeeder::class);
    Setting::set('weekly_dictation_cleanup', false);
    FakeLlm::bind()->push(['hasMeaningfulSpeech' => false, 'transcription' => 'eh', 'reason' => 'NO_SPEECH']);
    $dictation = geminiDictation($this->me);

    (new TranscribeDictation($dictation->id))->handle($this->whisper, app(GeminiDictationTranscriber::class));

    $dictation->refresh();
    expect($dictation->status)->toBe(TranscriptionStatus::Done)
        ->and($dictation->text)->toBe('')
        ->and($dictation->warning)->toBe('no_speech');
});

it('si Gemini falla, el Job lanza el error para reintentar y guarda el motivo', function () {
    FakeLlm::bind()->push(new LlmUnavailable('Gemini respondió 503.'));
    $dictation = geminiDictation($this->me);

    expect(fn () => (new TranscribeDictation($dictation->id))->handle($this->whisper, app(GeminiDictationTranscriber::class)))
        ->toThrow(RuntimeException::class);

    expect($dictation->refresh()->last_error)->toContain('503');
});

it('con la API real envía el audio en línea, en base64, y lo apunta en ai_usage', function () {
    Http::preventStrayRequests();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [['content' => ['parts' => [['text' => '{"hasMeaningfulSpeech":true,"transcription":"Texto dictado","reason":"OK"}']], 'role' => 'model'], 'finishReason' => 'STOP']],
        'usageMetadata' => ['promptTokenCount' => 300, 'candidatesTokenCount' => 20, 'totalTokenCount' => 320],
    ])]);
    $this->app->instance(LlmClient::class, new GeminiClient(app(AiUsageRecorder::class), 'clave-de-prueba', 'gemini-2.5-flash', 'https://generativelanguage.googleapis.com/v1beta', 30, 1));
    $dictation = geminiDictation($this->me);

    $result = app(GeminiDictationTranscriber::class)->transcribe($dictation, Storage::disk('local')->path('dictations/g.webm'));

    expect($result->text)->toBe('Texto dictado');
    Http::assertSent(function (Request $request): bool {
        $parts = $request->data()['contents'][0]['parts'];

        return $parts[0]['inlineData'] === ['mimeType' => 'audio/webm', 'data' => base64_encode('audio-opus')]
            && str_contains($parts[1]['text'], 'transcriptor de audio');
    });
    expect(AiUsage::query()->where('feature', AiFeature::DictationTranscription)->count())->toBe(1);
});
