<?php

use App\Domain\Chat\ConversationDirectory;
use App\Domain\Chat\MessageWriter;
use App\Domain\Chat\Transcription\FakeTranscriber;
use App\Domain\Chat\Transcription\TranscriptionService;
use App\Enums\MessageType;
use App\Http\Controllers\Chat\Media\MediaPayload;
use App\Models\Attachment;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/*
| Servir los audios y los adjuntos del chat (SPEC §15, D-069, D-071): URL firmada relativa Y la
| política (quien ve la conversación); audios con Range y su tipo; los de mensajes borrados u
| ocultos solo para quien modera; los del chat no se borran sueltos.
*/

beforeEach(function () {
    Storage::fake('local');
    $this->app->instance(TranscriptionService::class, new FakeTranscriber('Hola'));

    $this->ana = User::factory()->employee()->create(['name' => 'Ana']);
    $this->luis = User::factory()->employee()->create(['name' => 'Luis']);
    $this->admin = User::factory()->admin()->create();
    $this->project = Project::factory()->create();
    $this->project->addMember($this->ana);
    $this->project->addMember($this->luis);
    $this->chat = app(ConversationDirectory::class)->forProject($this->project);
    $this->direct = app(ConversationDirectory::class)->direct($this->ana, $this->luis);

    $this->wav = function (): UploadedFile {
        $samples = '';
        for ($i = 0; $i < 3200; $i++) {
            $samples .= pack('v', ($i * 37) % 65536);
        }
        $content = 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16)
            .'data'.pack('V', strlen($samples)).$samples;
        $path = (string) tempnam(sys_get_temp_dir(), 'c3wav');
        file_put_contents($path, $content);

        return new UploadedFile($path, 'nota de voz.wav', null, null, true);
    };
    $this->audioIn = function ($conversation): Attachment {
        $message = app(MessageWriter::class)->post($this->ana, $conversation, null, audio: ($this->wav)(), audioDurationMs: 200);

        return $message->attachments()->sole();
    };
    $this->imageIn = function ($conversation): Attachment {
        $message = app(MessageWriter::class)->post($this->ana, $conversation, 'Mira', files: [UploadedFile::fake()->image('plano.png', 600, 400)]);

        return $message->attachments()->sole();
    };
    $this->signed = fn (string $route, Attachment $attachment, int $minutes = 60): string => URL::temporarySignedRoute(
        $route,
        now()->addMinutes($minutes),
        ['attachment' => $attachment->id],
        absolute: false,
    );
});

it('sirve el audio en línea, con su tipo de audio, Accept-Ranges y las cabeceras de seguridad', function () {
    $audio = ($this->audioIn)($this->chat);
    $size = Storage::disk('local')->size($audio->path);

    $response = $this->actingAs($this->luis)->get(MediaPayload::audioUrl($audio))->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('audio/wav')
        ->and($response->headers->get('Accept-Ranges'))->toBe('bytes')
        ->and($response->headers->get('Content-Length'))->toBe((string) $size)
        ->and($response->headers->get('Content-Disposition'))->toStartWith('inline;')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toBe('sandbox')
        ->and($response->headers->get('Cache-Control'))->toContain('private')
        ->and($response->streamedContent())->toBe(Storage::disk('local')->get($audio->path));
});

it('atiende las peticiones Range con 206 y el trozo pedido', function () {
    $audio = ($this->audioIn)($this->chat);
    $content = (string) Storage::disk('local')->get($audio->path);
    $size = strlen($content);

    $response = $this->actingAs($this->luis)
        ->get(MediaPayload::audioUrl($audio), ['Range' => 'bytes=100-199'])
        ->assertStatus(206);

    expect($response->headers->get('Content-Range'))->toBe("bytes 100-199/{$size}")
        ->and($response->headers->get('Content-Length'))->toBe('100')
        ->and($response->headers->get('Content-Type'))->toBe('audio/wav')
        ->and($response->streamedContent())->toBe(substr($content, 100, 100));

    // Desde un punto hasta el final (lo que pide el navegador al saltar en el audio).
    $tail = $this->actingAs($this->luis)
        ->get(MediaPayload::audioUrl($audio), ['Range' => 'bytes='.($size - 10).'-'])
        ->assertStatus(206);
    expect($tail->streamedContent())->toBe(substr($content, -10));

    // Un rango fuera del archivo: 416.
    $this->actingAs($this->luis)
        ->get(MediaPayload::audioUrl($audio), ['Range' => 'bytes='.($size + 10).'-'.($size + 20)])
        ->assertStatus(416);
});

it('los audios que fileinfo toma por vídeo se sirven como audio', function (string $stored, string $served, string $extension) {
    $message = $this->chat->messages()->create(['user_id' => $this->ana->id, 'type' => MessageType::Audio]);
    Storage::disk('local')->put("attachments/chat/x.{$extension}", 'audio');
    $audio = $message->attachments()->create([
        'user_id' => $this->ana->id, 'disk' => 'local', 'path' => "attachments/chat/x.{$extension}",
        'original_name' => "nota.{$extension}", 'mime' => $stored, 'size' => 5,
    ]);

    $this->actingAs($this->luis)->get(MediaPayload::audioUrl($audio))
        ->assertOk()
        ->assertHeader('Content-Type', $served);
})->with([
    ['video/webm', 'audio/webm', 'webm'],
    ['audio/webm', 'audio/webm', 'webm'],
    ['video/mp4', 'audio/mp4', 'm4a'],
    ['audio/x-m4a', 'audio/mp4', 'm4a'],
    ['application/ogg', 'audio/ogg', 'ogg'],
    ['audio/mpeg', 'audio/mpeg', 'mp3'],
]);

it('sin firma, con la firma alterada o caducada no sirve nada', function () {
    $audio = ($this->audioIn)($this->chat);

    $this->actingAs($this->luis)->get("/chat/audios/{$audio->id}")->assertForbidden();
    $this->actingAs($this->luis)->get(MediaPayload::audioUrl($audio).'x')->assertForbidden();
    $this->actingAs($this->luis)->get(($this->signed)('chat.media.audio', $audio, -1))->assertForbidden();
});

it('la ruta de audios solo sirve audios del chat', function () {
    $image = ($this->imageIn)($this->chat);

    $this->actingAs($this->ana)->get(($this->signed)('chat.media.audio', $image))->assertNotFound();
});

it('audios e imágenes del chat solo para quien puede ver la conversación', function (string $who, string $where, bool $allowed) {
    $people = [
        'participante' => $this->luis,
        'admin' => $this->admin,
        'no participante' => User::factory()->employee()->create(),
        'responsable' => User::factory()->departmentManager()->create(),
    ];
    $conversation = $where === 'directa' ? $this->direct : $this->chat;
    $audio = ($this->audioIn)($conversation);
    $image = ($this->imageIn)($conversation);

    foreach ([
        MediaPayload::audioUrl($audio),
        ($this->signed)('attachments.show', $image),
        ($this->signed)('attachments.thumbnail', $image),
    ] as $url) {
        $response = $this->actingAs($people[$who])->get($url);
        $allowed ? $response->assertOk() : $response->assertForbidden();
    }
})->with([
    ['participante', 'proyecto', true],
    ['participante', 'directa', true],
    ['admin', 'proyecto', true],
    ['admin', 'directa', false],
    ['no participante', 'proyecto', false],
    ['no participante', 'directa', false],
    ['responsable', 'proyecto', false],
]);

it('un cliente va a su portal y un invitado al login', function () {
    $audio = ($this->audioIn)($this->chat);

    $this->actingAs(User::factory()->client()->create())->get(MediaPayload::audioUrl($audio))->assertRedirect('/portal');
    $this->app['auth']->forgetGuards();
    $this->get(MediaPayload::audioUrl($audio))->assertRedirect('/login');
});

it('quien sale del proyecto deja de poder descargar lo del chat', function () {
    $audio = ($this->audioIn)($this->chat);

    $this->project->members()->detach($this->luis->id);

    $this->actingAs($this->luis)->get(MediaPayload::audioUrl($audio))->assertForbidden();
});

it('lo de un mensaje borrado u ocultado solo lo recibe quien modera', function (string $state) {
    $audio = ($this->audioIn)($this->chat);
    $image = ($this->imageIn)($this->chat);
    $writer = app(MessageWriter::class);

    foreach ([$audio, $image] as $attachment) {
        $message = Message::query()->findOrFail($attachment->attachable_id);
        $state === 'borrado' ? $writer->delete($this->ana, $message) : $writer->setHidden($this->admin, $message, true);
    }

    foreach ([MediaPayload::audioUrl($audio), ($this->signed)('attachments.show', $image)] as $url) {
        $this->actingAs($this->luis)->get($url)->assertForbidden();
        $this->actingAs($this->ana)->get($url)->assertForbidden();
        $this->actingAs($this->admin)->get($url)->assertOk();
    }
})->with(['borrado', 'oculto']);

it('en una directa, lo de un mensaje borrado no lo recibe nadie', function () {
    $audio = ($this->audioIn)($this->direct);
    app(MessageWriter::class)->delete($this->ana, Message::query()->findOrFail($audio->attachable_id));

    foreach ([$this->ana, $this->luis, $this->admin] as $user) {
        $this->actingAs($user)->get(MediaPayload::audioUrl($audio))->assertForbidden();
    }
});

it('los adjuntos del chat no se borran sueltos: se borra el mensaje', function () {
    $image = ($this->imageIn)($this->chat);

    foreach ([$this->ana, $this->admin] as $user) {
        $this->actingAs($user)->delete("/adjuntos/{$image->id}")->assertForbidden();
    }

    Storage::disk('local')->assertExists($image->path);
    expect(Attachment::query()->whereKey($image->id)->exists())->toBeTrue();
});

it('los SVG del chat se descargan siempre, nunca se muestran', function () {
    $message = app(MessageWriter::class)->post($this->ana, $this->chat, null, files: [
        UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>')->mimeType('image/svg+xml'),
    ]);
    $svg = $message->attachments()->sole();

    $response = $this->actingAs($this->luis)->get(($this->signed)('attachments.show', $svg))->assertOk();

    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment;')
        ->and($response->headers->get('Content-Type'))->toBe('application/octet-stream');

    $message->load(MediaPayload::RELATIONS);
    expect(MediaPayload::of($message)['attachments'][0])->toMatchArray(['kind' => 'svg', 'is_image' => false, 'thumbnail_url' => null]);
});

it('MediaPayload no lleva nada de un mensaje borrado u oculto, salvo que se pida para moderar', function () {
    $audio = ($this->audioIn)($this->chat);
    $message = Message::query()->findOrFail($audio->attachable_id);
    app(MessageWriter::class)->setHidden($this->admin, $message, true);

    $message = Message::query()->with(MediaPayload::RELATIONS)->findOrFail($message->id);

    expect(MediaPayload::of($message))->toBe(['attachments' => [], 'audio' => null, 'transcription' => null])
        ->and(MediaPayload::of($message, reveal: true)['audio']['attachment_id'])->toBe($audio->id)
        ->and(MediaPayload::of($message, reveal: true)['transcription']['status'])->toBe('done');
});
