<?php

use App\Domain\Chat\ConversationDirectory;
use App\Domain\Chat\MessageWriter;
use App\Domain\Chat\Transcription\FakeTranscriber;
use App\Domain\Chat\Transcription\TranscriptionService;
use App\Enums\TranscriptionStatus;
use App\Models\AudioTranscription;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
| Consulta periódica del estado de las transcripciones (sin tiempo real): GET /chat/transcripciones.
| Una petición para todos los audios pendientes a la vista y solo de conversaciones visibles.
*/

beforeEach(function () {
    Storage::fake('local');
    $this->app->instance(TranscriptionService::class, new FakeTranscriber('Nos vemos en la obra'));

    $this->ana = User::factory()->employee()->create();
    $this->luis = User::factory()->employee()->create();
    $this->project = Project::factory()->create();
    $this->project->addMember($this->ana);
    $this->project->addMember($this->luis);
    $this->chat = app(ConversationDirectory::class)->forProject($this->project);
    $this->direct = app(ConversationDirectory::class)->direct($this->ana, $this->luis);

    $this->audio = function ($conversation): Message {
        $samples = str_repeat("\0\0", 1600);
        $path = (string) tempnam(sys_get_temp_dir(), 'c3wav');
        file_put_contents($path, 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16).'data'.pack('V', strlen($samples)).$samples);

        return app(MessageWriter::class)->post($this->ana, $conversation, null, audio: new UploadedFile($path, 'nota.wav', null, null, true), audioDurationMs: 800);
    };
    $this->poll = fn (User $user, array $ids) => $this->actingAs($user)->getJson('/chat/transcripciones?mensajes='.implode(',', $ids));
});

it('devuelve el estado, el texto cuando está hecha y una URL nueva del audio', function () {
    $done = ($this->audio)($this->chat);
    Queue::fake();
    $pending = ($this->audio)($this->chat);

    $response = ($this->poll)($this->luis, [$pending->id, $done->id])->assertOk();

    $response->assertJsonCount(2, 'messages')
        ->assertJsonPath('messages.0.id', $done->id)
        ->assertJsonPath('messages.0.transcription.status', 'done')
        ->assertJsonPath('messages.0.transcription.text', 'Nos vemos en la obra')
        ->assertJsonPath('messages.1.id', $pending->id)
        ->assertJsonPath('messages.1.transcription.status', 'pending')
        ->assertJsonPath('messages.1.transcription.text', null)
        ->assertJsonPath('messages.1.audio.duration_ms', 800);
    expect($response->json('messages.1.audio.url'))->toStartWith('/chat/audios/');
});

it('un audio sin voz termina con el texto vacío', function () {
    $this->app->instance(TranscriptionService::class, (new FakeTranscriber)->respondWith(''));
    $message = ($this->audio)($this->chat);

    ($this->poll)($this->ana, [$message->id])
        ->assertJsonPath('messages.0.transcription.status', 'done')
        ->assertJsonPath('messages.0.transcription.text', '');
});

it('solo devuelve audios de conversaciones que quien pregunta puede ver', function () {
    $inProject = ($this->audio)($this->chat);
    $inDirect = ($this->audio)($this->direct);
    $admin = User::factory()->admin()->create();
    $outsider = User::factory()->employee()->create();

    expect(collect(($this->poll)($this->luis, [$inProject->id, $inDirect->id])->json('messages'))->pluck('id')->all())
        ->toBe([$inProject->id, $inDirect->id])
        ->and(collect(($this->poll)($admin, [$inProject->id, $inDirect->id])->json('messages'))->pluck('id')->all())
        ->toBe([$inProject->id])
        ->and(($this->poll)($outsider, [$inProject->id, $inDirect->id])->json('messages'))->toBe([]);
});

it('no devuelve audios borrados ni ocultos, ni mensajes que no son de audio', function () {
    $deleted = ($this->audio)($this->chat);
    $hidden = ($this->audio)($this->chat);
    $text = app(MessageWriter::class)->post($this->ana, $this->chat, 'Hola');
    app(MessageWriter::class)->delete($this->ana, $deleted);
    app(MessageWriter::class)->setHidden(User::factory()->admin()->create(), $hidden, true);

    expect(($this->poll)($this->luis, [$deleted->id, $hidden->id, $text->id])->json('messages'))->toBe([]);
});

it('valida la lista de mensajes', function (string $query) {
    $this->actingAs($this->ana)->getJson('/chat/transcripciones'.$query)->assertUnprocessable();
})->with(['', '?mensajes=', '?mensajes=1,a', '?mensajes=1;2', '?mensajes[]=1']);

it('atiende como mucho 50 mensajes por consulta', function () {
    $message = ($this->audio)($this->chat);
    $ids = [...range(900_000, 900_060), $message->id];

    // El audio real va el último: queda fuera de los 50 primeros.
    expect(($this->poll)($this->ana, $ids)->json('messages'))->toBe([]);
});

it('un fallo del motor se ve como fallida hasta que se reintenta', function () {
    Queue::fake();
    $message = ($this->audio)($this->chat);
    AudioTranscription::query()->where('message_id', $message->id)->update(['status' => TranscriptionStatus::Failed, 'attempts' => 3]);

    ($this->poll)($this->ana, [$message->id])
        ->assertJsonPath('messages.0.transcription.status', 'failed')
        ->assertJsonPath('messages.0.transcription.text', null);
});
