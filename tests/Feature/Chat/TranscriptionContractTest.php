<?php

use App\Domain\Chat\ConversationDirectory;
use App\Domain\Chat\MessageWriter;
use App\Domain\Chat\Transcription\AudioTranscriptions;
use App\Domain\Chat\Transcription\FakeTranscriber;
use App\Domain\Chat\Transcription\TranscriptionFailed;
use App\Domain\Chat\Transcription\TranscriptionService;
use App\Domain\Chat\Transcription\WhisperServerTranscriber;
use App\Enums\MessageType;
use App\Enums\TranscriptionStatus;
use App\Jobs\TranscribeAudioMessage;
use App\Models\AudioTranscription;
use App\Models\User;
use App\Notifications\Chat\TranscriptionsFailing;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * Subida con un fichero real: el tipo lo detecta fileinfo por el contenido, como en producción
 * (UploadedFile::fake() lo deduciría del nombre).
 */
function chat_real_upload(string $name, string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'chat');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, null, null, true);
}

/*
| Transcripción obligatoria de los audios (SPEC §12, D-070): todo audio acaba con su texto.
*/

beforeEach(function () {
    Storage::fake('local');
    $this->fake = new FakeTranscriber('Hola equipo');
    $this->app->instance(TranscriptionService::class, $this->fake);
    $this->ana = User::factory()->employee()->create();
    $this->luis = User::factory()->employee()->create();
    $this->chat = app(ConversationDirectory::class)->direct($this->ana, $this->luis);
    // Un WAV real (cabecera RIFF + 0,1 s de silencio a 16 kHz): fileinfo lo reconoce como audio.
    $this->audio = function (): UploadedFile {
        $samples = str_repeat("\0\0", 1600);
        $wav = 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16)
            .'data'.pack('V', strlen($samples)).$samples;

        return chat_real_upload('nota.wav', $wav);
    };
});

it('todo audio se guarda con su transcripción y acaba con texto', function () {
    $message = app(MessageWriter::class)->post($this->ana, $this->chat, null, audio: ($this->audio)());

    $transcription = AudioTranscription::query()->where('message_id', $message->id)->firstOrFail();
    expect($message->type)->toBe(MessageType::Audio)
        ->and($transcription->status)->toBe(TranscriptionStatus::Done)
        ->and($transcription->text)->toBe('Hola equipo')
        ->and($transcription->engine)->toBe('fake')
        ->and($transcription->attempts)->toBe(1)
        ->and($message->attachments()->firstOrFail()->mime)->toBeIn(['audio/wav', 'audio/x-wav', 'audio/vnd.wave']);
});

it('un audio con otro tipo real se rechaza y no deja nada a medias', function () {
    $fake = chat_real_upload('nota.wav', "%PDF-1.4\n%âãÏÓ\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");

    expect(fn () => app(MessageWriter::class)->post($this->ana, $this->chat, null, audio: $fake))->toThrow(RuntimeException::class)
        ->and($this->chat->messages()->count())->toBe(0);
});

it('la revisión avisa al admin UNA vez tras agotar intentos y sigue probando como mucho cada hora', function () {
    Notification::fake();
    // La cola no ejecuta el job: la transcripción sigue fallida entre una revisión y otra.
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $message = $this->chat->messages()->create(['user_id' => $this->ana->id, 'type' => MessageType::Audio]);
    $attachment = $message->attachments()->create([
        'user_id' => $this->ana->id, 'disk' => 'local', 'path' => 'attachments/chat/x.webm',
        'original_name' => 'nota.webm', 'mime' => 'audio/webm', 'size' => 10,
    ]);

    $transcription = AudioTranscription::query()->create([
        'message_id' => $message->id, 'attachment_id' => $attachment->id,
        'status' => TranscriptionStatus::Failed, 'attempts' => AudioTranscriptions::MAX_ATTEMPTS, 'queued_at' => now()->subHours(2),
    ]);
    $transcriptions = app(AudioTranscriptions::class);

    expect($transcriptions->requeue())->toBe(['requeued' => 1, 'notified' => 1])
        ->and($transcription->fresh()->status)->toBe(TranscriptionStatus::Failed)
        ->and($transcription->fresh()->admin_notified_at)->not->toBeNull();
    Queue::assertPushed(TranscribeAudioMessage::class, 1);

    // 20 minutos después sigue fallida: ni otro aviso ni otro intento (como mucho uno por hora).
    $this->travel(20)->minutes();
    expect($transcriptions->requeue())->toBe(['requeued' => 0, 'notified' => 0]);

    // Pasada la hora, otro intento, pero sin otro aviso.
    $this->travel(45)->minutes();
    expect($transcriptions->requeue())->toBe(['requeued' => 1, 'notified' => 0]);

    Notification::assertSentToTimes($admin, TranscriptionsFailing::class, 1);
    Queue::assertPushed(TranscribeAudioMessage::class, 2);
});

it('un fallo del motor deja la transcripción en failed con el error y relanza la excepción', function () {
    $this->fake->respondWith(new TranscriptionFailed('El transcriptor no responde'));
    $message = $this->chat->messages()->create(['user_id' => $this->ana->id, 'type' => MessageType::Audio]);
    $attachment = $message->attachments()->create([
        'user_id' => $this->ana->id, 'disk' => 'local', 'path' => 'attachments/chat/y.webm',
        'original_name' => 'nota.webm', 'mime' => 'audio/webm', 'size' => 10,
    ]);
    Storage::disk('local')->put('attachments/chat/y.webm', 'audio');

    expect(fn () => app(AudioTranscriptions::class)->forAudio($message, $attachment))->toThrow(TranscriptionFailed::class);

    $transcription = AudioTranscription::query()->where('message_id', $message->id)->firstOrFail();
    expect($transcription->status)->toBe(TranscriptionStatus::Failed)
        ->and($transcription->last_error)->toContain('no responde');

    app(AudioTranscriptions::class)->retry($transcription);
    expect($transcription->fresh()->status)->toBe(TranscriptionStatus::Done);
});

it('backfill transcribe los audios sin transcripción', function () {
    $message = $this->chat->messages()->create(['user_id' => $this->ana->id, 'type' => MessageType::Audio]);
    $message->attachments()->create([
        'user_id' => $this->ana->id, 'disk' => 'local', 'path' => 'attachments/chat/z.webm',
        'original_name' => 'nota.webm', 'mime' => 'audio/webm', 'size' => 10,
    ]);
    Storage::disk('local')->put('attachments/chat/z.webm', 'audio');

    $this->artisan('transcriptions:backfill')->assertSuccessful();

    expect(AudioTranscription::query()->where('message_id', $message->id)->value('text'))->toBe('Hola equipo');
});

it('el motor whisper envía el audio al servidor local y lee el texto y la duración', function () {
    Http::fake(['127.0.0.1:18091/inference' => Http::response([
        'text' => " Hola, equipo de fer\nretería. ",
        'language' => 'spanish',
        'duration' => 2.5,
        'segments' => [['start' => 0.0, 'end' => 1.2, 'text' => ' Hola, equipo de fer'], ['start' => 1.2, 'end' => 2.4, 'text' => 'retería.']],
    ])]);
    $path = tempnam(sys_get_temp_dir(), 'audio');
    file_put_contents($path, 'audio');

    $result = (new WhisperServerTranscriber('http://127.0.0.1:18091', 'small', 60))->transcribe($path, 'es');

    expect($result->text)->toBe('Hola, equipo de ferretería.')
        ->and($result->durationMs)->toBe(2500)
        ->and($result->language)->toBe('es');
    Http::assertSent(fn ($request) => $request->url() === 'http://127.0.0.1:18091/inference');

    Http::fake(['127.0.0.1:18092/inference' => Http::response('error', 500)]);
    expect(fn () => (new WhisperServerTranscriber('http://127.0.0.1:18092', 'small', 60))->transcribe($path, 'es'))
        ->toThrow(TranscriptionFailed::class);
    unlink($path);
});
