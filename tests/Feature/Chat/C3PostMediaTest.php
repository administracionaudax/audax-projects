<?php

use App\Domain\Chat\ConversationDirectory;
use App\Domain\Chat\MessageWriter;
use App\Domain\Chat\Transcription\FakeTranscriber;
use App\Domain\Chat\Transcription\TranscriptionService;
use App\Enums\MessageType;
use App\Enums\ProjectStatus;
use App\Enums\TranscriptionStatus;
use App\Events\Chat\MessagePosted;
use App\Http\Controllers\Chat\Media\StoreMediaMessageRequest;
use App\Jobs\TranscribeAudioMessage;
use App\Models\Attachment;
use App\Models\AudioTranscription;
use App\Models\Message;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
| Publicar en el chat con adjuntos y audio (SPEC §12, D-069, D-070): POST /chat/{c}/multimedia.
| Siempre por MessageWriter; tipos reales, tamaños y duración máxima del audio; permisos (D-071).
*/

beforeEach(function () {
    Storage::fake('local');
    $this->app->instance(TranscriptionService::class, new FakeTranscriber('Hola equipo, mañana entregamos'));

    $this->ana = User::factory()->employee()->create(['name' => 'Ana']);
    $this->luis = User::factory()->employee()->create(['name' => 'Luis']);
    $this->project = Project::factory()->create();
    $this->project->addMember($this->ana);
    $this->project->addMember($this->luis);
    $this->chat = app(ConversationDirectory::class)->forProject($this->project);
    $this->direct = app(ConversationDirectory::class)->direct($this->ana, $this->luis);

    // Un WAV real (cabecera RIFF + silencio): fileinfo lo reconoce como audio por su contenido.
    $this->wav = function (int $milliseconds = 1000, string $name = 'nota.wav'): UploadedFile {
        $samples = str_repeat("\0\0", intdiv(16000 * $milliseconds, 1000));
        $content = 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16)
            .'data'.pack('V', strlen($samples)).$samples;
        $path = (string) tempnam(sys_get_temp_dir(), 'c3wav');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    };
    $this->realFile = function (string $name, string $content): UploadedFile {
        $path = (string) tempnam(sys_get_temp_dir(), 'c3file');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    };
    $this->png = function (): string {
        $image = imagecreatetruecolor(8, 8);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    };
    $this->send = fn (?User $user, array $data, $conversation = null) => ($user === null ? $this : $this->actingAs($user))
        ->withHeaders(['Accept' => 'application/json'])
        ->post('/chat/'.($conversation ?? $this->chat)->id.'/multimedia', $data);
});

it('publica varios adjuntos con texto y devuelve el mensaje con sus URLs firmadas', function () {
    Event::fake([MessagePosted::class]);

    $response = ($this->send)($this->ana, [
        'body' => 'Os paso el presupuesto y la foto',
        'files' => [
            UploadedFile::fake()->create('Presupuesto.pdf', 120, 'application/pdf'),
            UploadedFile::fake()->image('obra.jpg', 800, 600),
        ],
    ])->assertCreated();

    $message = Message::query()->sole();
    $attachments = Attachment::query()->orderBy('id')->get();

    expect($message->type)->toBe(MessageType::Text)
        ->and($message->body)->toBe('Os paso el presupuesto y la foto')
        ->and($message->user_id)->toBe($this->ana->id)
        ->and($attachments)->toHaveCount(2)
        ->and($attachments->pluck('project_id')->unique()->all())->toBe([$this->project->id])
        ->and($attachments->every(fn (Attachment $a) => $a->attachable_type === Message::class && $a->attachable_id === $message->id))->toBeTrue();

    $response->assertJsonPath('message.id', $message->id)
        ->assertJsonPath('message.conversation_id', $this->chat->id)
        ->assertJsonPath('message.type', 'text')
        ->assertJsonPath('message.audio', null)
        ->assertJsonPath('message.transcription', null)
        ->assertJsonPath('message.attachments.0.original_name', 'Presupuesto.pdf')
        ->assertJsonPath('message.attachments.0.kind', 'file')
        ->assertJsonPath('message.attachments.1.kind', 'image')
        ->assertJsonPath('message.attachments.1.is_image', true);

    expect($response->json('message.attachments.0.url'))->toStartWith("/adjuntos/{$attachments[0]->id}?expires=")
        ->and($response->json('message.attachments.0.url'))->toContain('signature=');
    Event::assertDispatched(MessagePosted::class, fn (MessagePosted $event) => $event->message->is($message));
});

it('solo con archivos el mensaje es de tipo archivo; en una directa se guardan fuera de los proyectos', function () {
    ($this->send)($this->ana, ['files' => [UploadedFile::fake()->create('acta.txt', 1, 'text/plain')]], $this->direct)
        ->assertCreated()
        ->assertJsonPath('message.type', 'file');

    $attachment = Attachment::query()->sole();
    expect($attachment->project_id)->toBeNull()
        ->and($attachment->path)->toStartWith("attachments/chat/{$this->direct->id}/");
});

it('publica un audio con su transcripción obligatoria, que acaba con texto', function () {
    $response = ($this->send)($this->ana, ['audio' => ($this->wav)(1500), 'duration_ms' => 1500])->assertCreated();

    $message = Message::query()->sole();
    $transcription = AudioTranscription::query()->sole();

    expect($message->type)->toBe(MessageType::Audio)
        ->and($transcription->message_id)->toBe($message->id)
        ->and($transcription->status)->toBe(TranscriptionStatus::Done)
        ->and($transcription->text)->toBe('Hola equipo, mañana entregamos');

    $response->assertJsonPath('message.type', 'audio')
        ->assertJsonPath('message.attachments', [])
        ->assertJsonPath('message.audio.attachment_id', $transcription->attachment_id)
        ->assertJsonPath('message.audio.mime', 'audio/wav')
        ->assertJsonPath('message.transcription.status', 'done')
        ->assertJsonPath('message.transcription.text', 'Hola equipo, mañana entregamos');
    expect($response->json('message.audio.url'))->toStartWith("/chat/audios/{$transcription->attachment_id}?expires=");
});

it('guarda la duración que envía el navegador hasta que el transcriptor mide la real', function () {
    Queue::fake();

    ($this->send)($this->ana, ['audio' => ($this->wav)(1000), 'duration_ms' => 1234])
        ->assertCreated()
        ->assertJsonPath('message.audio.duration_ms', 1234)
        ->assertJsonPath('message.transcription.status', 'pending')
        ->assertJsonPath('message.transcription.text', null);

    expect(AudioTranscription::query()->sole()->audio_duration_ms)->toBe(1234);
    Queue::assertPushed(TranscribeAudioMessage::class, 1);
});

it('valida el audio: tipo real, duración obligatoria, mínima, máxima del ajuste y que cuadre con el archivo', function (Closure $data, string $field) {
    Setting::set('max_audio_seconds', 60);

    ($this->send)($this->ana, $data->call($this))->assertUnprocessable()->assertJsonValidationErrors([$field]);

    expect(Message::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'un PDF con nombre de audio' => [fn () => ['audio' => ($this->realFile)('nota.wav', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n"), 'duration_ms' => 1000], 'audio'],
    'una extensión que no es de audio' => [fn () => ['audio' => ($this->wav)(1000, 'nota.pdf'), 'duration_ms' => 1000], 'audio'],
    'sin duración' => [fn () => ['audio' => ($this->wav)(1000)], 'duration_ms'],
    'duración que no es un número' => [fn () => ['audio' => ($this->wav)(1000), 'duration_ms' => 'mucho'], 'duration_ms'],
    'demasiado corto' => [fn () => ['audio' => ($this->wav)(100), 'duration_ms' => 100], 'duration_ms'],
    'más largo que el máximo del ajuste' => [fn () => ['audio' => ($this->wav)(1000), 'duration_ms' => 60_000 + StoreMediaMessageRequest::DURATION_TOLERANCE_MS + 1], 'duration_ms'],
    // 10 s de audio (320 KB) declarados como 1 s: el archivo no cuadra con la duración.
    'un archivo largo con una duración corta' => [fn () => ['audio' => ($this->wav)(10_000), 'duration_ms' => 1000], 'audio'],
]);

it('admite un audio de la duración máxima del ajuste (con el margen de MediaRecorder)', function () {
    Setting::set('max_audio_seconds', 60);

    ($this->send)($this->ana, ['audio' => ($this->wav)(500), 'duration_ms' => 60_000 + StoreMediaMessageRequest::DURATION_TOLERANCE_MS])
        ->assertCreated();
});

it('el audio no puede pasar del tamaño máximo de los adjuntos', function () {
    Setting::set('max_attachment_mb', 1);

    ($this->send)($this->ana, ['audio' => UploadedFile::fake()->create('nota.webm', 1500, 'audio/webm'), 'duration_ms' => 60_000])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['audio' => 'El audio puede pesar como máximo 1 MB.']);
});

it('valida los adjuntos como en la Fase 1: tipo real, extensión, tamaño y número', function (Closure $data, string $field) {
    Setting::set('max_attachment_mb', 1);

    ($this->send)($this->ana, $data->call($this))->assertUnprocessable()->assertJsonValidationErrors([$field]);

    expect(Message::query()->count())->toBe(0)
        ->and(Attachment::query()->count())->toBe(0);
})->with([
    'tipo no permitido' => [fn () => ['files' => [UploadedFile::fake()->create('script.php', 1, 'application/x-php')]], 'files.0'],
    'extensión que no casa con el contenido' => [fn () => ['files' => [($this->realFile)('foto.pdf', ($this->png)())]], 'files.0'],
    'demasiado grande' => [fn () => ['files' => [UploadedFile::fake()->create('grande.pdf', 1500, 'application/pdf')]], 'files.0'],
    'más de 10' => [fn () => ['files' => array_map(fn (int $i) => UploadedFile::fake()->create("n{$i}.txt", 1, 'text/plain'), range(1, 11))], 'files'],
]);

it('un mensaje vacío no se publica', function () {
    ($this->send)($this->ana, ['body' => '   '])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['body' => 'Escribe algo o adjunta un archivo.']);
});

it('responde en un hilo solo a mensajes de la misma conversación', function () {
    $root = app(MessageWriter::class)->post($this->ana, $this->chat, 'Pregunta');
    $foreign = app(MessageWriter::class)->post($this->ana, $this->direct, 'De otra conversación');

    ($this->send)($this->luis, ['parent_id' => $root->id, 'files' => [UploadedFile::fake()->create('respuesta.txt', 1, 'text/plain')]])
        ->assertCreated()
        ->assertJsonPath('message.parent_id', $root->id);

    ($this->send)($this->luis, ['parent_id' => $foreign->id, 'files' => [UploadedFile::fake()->create('otra.txt', 1, 'text/plain')]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['parent_id']);
});

it('solo publica quien puede escribir en la conversación', function (string $who, string $where, int $status) {
    $people = [
        'participante' => $this->ana,
        'admin' => User::factory()->admin()->create(),
        'no participante' => User::factory()->employee()->create(),
        'responsable' => User::factory()->departmentManager()->create(),
        'cliente' => User::factory()->client()->create(),
        'invitado' => null,
    ];
    $conversation = $where === 'directa' ? $this->direct : $this->chat;

    ($this->send)($people[$who], ['files' => [UploadedFile::fake()->create('nota.txt', 1, 'text/plain')]], $conversation)
        ->assertStatus($status);

    expect(Message::query()->count())->toBe($status === 201 ? 1 : 0)
        ->and(Storage::disk('local')->allFiles() === [])->toBe($status !== 201);
})->with([
    ['participante', 'proyecto', 201],
    ['participante', 'directa', 201],
    ['admin', 'proyecto', 403],
    ['admin', 'directa', 403],
    ['no participante', 'proyecto', 403],
    ['no participante', 'directa', 403],
    ['responsable', 'proyecto', 403],
    ['cliente', 'proyecto', 403],
    ['invitado', 'proyecto', 401],
]);

it('en el chat de un proyecto archivado ya no se publica', function () {
    $this->project->update(['status' => ProjectStatus::Archived]);

    ($this->send)($this->ana, ['audio' => ($this->wav)(1000), 'duration_ms' => 1000])->assertForbidden();

    expect(Message::query()->count())->toBe(0);
});

it('una conversación que no existe da 404', function () {
    $this->actingAs($this->ana)
        ->withHeaders(['Accept' => 'application/json'])
        ->post('/chat/999999/multimedia', ['body' => 'Hola'])
        ->assertNotFound();
});
