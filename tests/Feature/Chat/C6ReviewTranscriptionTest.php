<?php

use App\Domain\Chat\ConversationDirectory;
use App\Domain\Chat\Transcription\AudioTranscriptions;
use App\Domain\Chat\Transcription\FakeTranscriber;
use App\Domain\Chat\Transcription\TranscriptionService;
use App\Domain\Chat\Transcription\WhisperServerTranscriber;
use App\Enums\MessageType;
use App\Enums\TranscriptionStatus;
use App\Http\Controllers\Chat\Media\StoreMediaMessageRequest;
use App\Jobs\TranscribeAudioMessage;
use App\Models\AudioTranscription;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\Chat\TranscriptionsFailing;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
| Revisión global de la Fase 6 (D-116): un audio no bloquea el único proceso de transcripción.
| - El tamaño tiene que cuadrar con la duración declarada (hasta 16 KB/s: el navegador graba a
|   64 kbit/s, unos 8 KB/s): un WAV de 30 minutos declarado como de 5 no entra.
| - whisper-server procesa como mucho la duración máxima (campo `duration` de /inference).
| - La revisión cada 15 minutos: pendientes atascadas (más de 15 min), interrumpidas (más de
|   50 min «en curso»), como mucho un intento por hora tras avisar y, a partir de un tope, ya no
|   se relanza sola (solo el admin).
*/

beforeEach(function () {
    Storage::fake('local');
    $this->fake = new FakeTranscriber('Hola equipo');
    $this->app->instance(TranscriptionService::class, $this->fake);
    $this->ana = User::factory()->employee()->create();
    $this->luis = User::factory()->employee()->create();
    $this->chat = app(ConversationDirectory::class)->direct($this->ana, $this->luis);
    // WAV de 8 kHz y 8 bits (8.000 bytes por segundo, como un audio de 64 kbit/s).
    $this->wav = function (int $seconds): UploadedFile {
        $samples = str_repeat("\x80", 8000 * $seconds);
        $content = 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 8000, 1, 8)
            .'data'.pack('V', strlen($samples)).$samples;
        $path = (string) tempnam(sys_get_temp_dir(), 'c6wav');
        file_put_contents($path, $content);

        return new UploadedFile($path, 'nota.wav', null, null, true);
    };
    $this->transcription = function (array $attributes): AudioTranscription {
        $message = $this->chat->messages()->create(['user_id' => $this->ana->id, 'type' => MessageType::Audio]);
        $attachment = $message->attachments()->create([
            'user_id' => $this->ana->id, 'disk' => 'local', 'path' => "attachments/chat/{$message->id}.webm",
            'original_name' => 'nota.webm', 'mime' => 'audio/webm', 'size' => 10,
        ]);
        Storage::disk('local')->put("attachments/chat/{$message->id}.webm", 'audio');

        return AudioTranscription::query()->create(['message_id' => $message->id, 'attachment_id' => $attachment->id, ...$attributes]);
    };
});

it('un archivo de 30 minutos declarado como de 5 no entra; uno de 5 minutos a 64 kbit/s, sí', function () {
    $this->actingAs($this->ana)
        ->post("/chat/{$this->chat->id}/multimedia", ['audio' => ($this->wav)(1800), 'duration_ms' => 301_000], ['Accept' => 'application/json'])
        ->assertJsonValidationErrors('audio');

    $this->actingAs($this->ana)
        ->post("/chat/{$this->chat->id}/multimedia", ['audio' => ($this->wav)(300), 'duration_ms' => 300_000], ['Accept' => 'application/json'])
        ->assertCreated();

    expect(StoreMediaMessageRequest::MAX_BYTES_PER_SECOND)->toBe(16_000);
});

it('el job pide al motor que procese como mucho la duración máxima de los audios', function () {
    Setting::set('max_audio_seconds', 120);

    $this->actingAs($this->ana)
        ->post("/chat/{$this->chat->id}/multimedia", ['audio' => ($this->wav)(2), 'duration_ms' => 2000], ['Accept' => 'application/json'])
        ->assertCreated();

    expect($this->fake->lastMaxDurationMs)->toBe(120_000 + StoreMediaMessageRequest::DURATION_TOLERANCE_MS);
});

it('whisper-server recibe el campo duration y la duración real sale de su respuesta', function () {
    Http::fake(['127.0.0.1:18091/inference' => Http::response(['text' => ' Hola. ', 'language' => 'spanish', 'duration' => 1800.0, 'segments' => []])]);
    $path = (string) tempnam(sys_get_temp_dir(), 'audio');
    file_put_contents($path, 'audio');

    $result = (new WhisperServerTranscriber('http://127.0.0.1:18091', 'small', 60))->transcribe($path, 'es', 302_000);

    expect($result->durationMs)->toBe(1_800_000)->and($result->text)->toBe('Hola.');
    Http::assertSent(function (HttpRequest $request): bool {
        $parts = collect($request->data())->keyBy('name');

        return $parts->has('file') && ($parts['duration']['contents'] ?? null) === '302000' && ($parts['language']['contents'] ?? null) === 'es';
    });

    // Sin máximo, no se envía el campo.
    (new WhisperServerTranscriber('http://127.0.0.1:18091', 'small', 60))->transcribe($path, 'es');
    Http::assertSent(fn (HttpRequest $request): bool => ! collect($request->data())->contains('name', 'duration'));
    unlink($path);
});

it('un audio más largo que el máximo se transcribe hasta el máximo y el admin lo ve señalado', function () {
    $this->fake->durationMs = 1_800_000;
    $transcription = ($this->transcription)(['status' => TranscriptionStatus::Pending]);

    app(AudioTranscriptions::class)->dispatch($transcription);

    expect($transcription->fresh()->status)->toBe(TranscriptionStatus::Done)
        ->and($transcription->fresh()->audio_duration_ms)->toBe(1_800_000);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/transcripciones')
        ->assertInertia(fn ($page) => $page->where('transcriptions.0.over_limit', true));
});

it('la revisión vuelve a encolar las pendientes de más de 15 minutos y las «en curso» de más de 50', function () {
    Queue::fake();
    $recent = ($this->transcription)(['status' => TranscriptionStatus::Pending, 'queued_at' => now()->subMinutes(10)]);
    $stuck = ($this->transcription)(['status' => TranscriptionStatus::Pending, 'queued_at' => now()->subMinutes(16)]);
    $running = ($this->transcription)(['status' => TranscriptionStatus::Processing, 'queued_at' => now()->subMinutes(45), 'started_at' => now()->subMinutes(40), 'attempts' => 1]);
    $interrupted = ($this->transcription)(['status' => TranscriptionStatus::Processing, 'queued_at' => now()->subMinutes(55), 'started_at' => now()->subMinutes(51), 'attempts' => 1]);

    expect(app(AudioTranscriptions::class)->requeue())->toBe(['requeued' => 2, 'notified' => 0]);

    $pushed = Queue::pushed(TranscribeAudioMessage::class)->map(fn (TranscribeAudioMessage $job): int => $job->transcriptionId)->all();
    expect($pushed)->toEqualCanonicalizing([$stuck->id, $interrupted->id])
        ->and($recent->fresh()->queued_at->lt(now()->subMinutes(9)))->toBeTrue()
        ->and($running->fresh()->status)->toBe(TranscriptionStatus::Processing);
});

it('una interrumpida se vuelve a encolar aunque su job único no llegara a soltar el candado', function () {
    Queue::fake();
    $transcription = ($this->transcription)(['status' => TranscriptionStatus::Pending]);
    // El job entra en la cola (y toma su candado de job único), pero el worker se cae a mitad.
    app(AudioTranscriptions::class)->dispatch($transcription);
    $transcription->forceFill(['status' => TranscriptionStatus::Processing, 'started_at' => now(), 'attempts' => 1])->save();

    $this->travel(51)->minutes();
    expect(app(AudioTranscriptions::class)->requeue()['requeued'])->toBe(1);

    Queue::assertPushed(TranscribeAudioMessage::class, 2);
});

it('si el worker corta el job por tiempo, la transcripción queda fallida con su error', function () {
    $transcription = ($this->transcription)(['status' => TranscriptionStatus::Processing, 'attempts' => 3, 'started_at' => now()]);

    (new TranscribeAudioMessage($transcription->id))->failed(null);

    expect($transcription->fresh()->status)->toBe(TranscriptionStatus::Failed)
        ->and($transcription->fresh()->last_error)->toBe(__('chat_media.transcription.interrupted'));

    // Una ya hecha no se toca.
    $done = ($this->transcription)(['status' => TranscriptionStatus::Done, 'text' => 'Hola']);
    (new TranscribeAudioMessage($done->id))->failed(new RuntimeException('Tiempo agotado'));
    expect($done->fresh()->status)->toBe(TranscriptionStatus::Done);
});

it('a partir del tope de intentos ya no se relanza sola (pero avisa al admin y él puede relanzarla)', function () {
    Queue::fake();
    Notification::fake();
    $admin = User::factory()->admin()->create();
    $failed = ($this->transcription)(['status' => TranscriptionStatus::Failed, 'attempts' => AudioTranscriptions::GIVE_UP_ATTEMPTS, 'queued_at' => now()->subHours(3), 'last_error' => 'Tiempo agotado']);
    $stuck = ($this->transcription)(['status' => TranscriptionStatus::Processing, 'attempts' => AudioTranscriptions::GIVE_UP_ATTEMPTS, 'queued_at' => now()->subHours(3), 'started_at' => now()->subHours(2), 'admin_notified_at' => now()->subDay()]);

    expect(app(AudioTranscriptions::class)->requeue())->toBe(['requeued' => 0, 'notified' => 1]);
    $this->travel(2)->hours();
    expect(app(AudioTranscriptions::class)->requeue())->toBe(['requeued' => 0, 'notified' => 0]);

    Queue::assertNotPushed(TranscribeAudioMessage::class);
    Notification::assertSentToTimes($admin, TranscriptionsFailing::class, 1);
    expect($stuck->fresh()->status)->toBe(TranscriptionStatus::Failed)
        ->and($stuck->fresh()->last_error)->toBe(__('chat_media.transcription.interrupted'))
        ->and($failed->fresh()->last_error)->toBe('Tiempo agotado');

    // El admin la relanza a mano desde /admin/transcripciones.
    $this->actingAs($admin)->post("/admin/transcripciones/{$failed->id}/relanzar")->assertRedirect();
    Queue::assertPushed(TranscribeAudioMessage::class, 1);
});
