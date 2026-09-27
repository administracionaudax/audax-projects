<?php

use App\Domain\Chat\ConversationDirectory;
use App\Domain\Chat\MessageWriter;
use App\Domain\Chat\Transcription\AudioTranscriptions;
use App\Domain\Chat\Transcription\FakeTranscriber;
use App\Domain\Chat\Transcription\TranscriptionFailed;
use App\Domain\Chat\Transcription\TranscriptionService;
use App\Enums\MessageType;
use App\Enums\TranscriptionStatus;
use App\Models\AudioTranscription;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| /admin/transcripciones (SPEC §12, D-070): solo admin; estado de cada transcripción, filtros,
| «Relanzar» una o todas las fallidas (AudioTranscriptions::retry). De las directas no se enseña
| ni quién ni dónde (D-071).
*/

beforeEach(function () {
    Storage::fake('local');
    $this->fake = new FakeTranscriber('Texto de prueba');
    $this->app->instance(TranscriptionService::class, $this->fake);

    $this->admin = User::factory()->admin()->create();
    $this->ana = User::factory()->employee()->create(['name' => 'Ana Audio']);
    $this->luis = User::factory()->employee()->create(['name' => 'Luis']);
    $this->project = Project::factory()->create(['name' => 'Web corporativa', 'code' => 'ARR-WEB']);
    $this->project->addMember($this->ana);
    $this->project->addMember($this->luis);
    $this->chat = app(ConversationDirectory::class)->forProject($this->project);
    $this->direct = app(ConversationDirectory::class)->direct($this->ana, $this->luis);

    // Transcripción creada a mano en el estado que se quiera (sin pasar por el motor).
    $this->transcription = function (Conversation $conversation, TranscriptionStatus $status, array $attributes = []): AudioTranscription {
        $message = $conversation->messages()->create(['user_id' => $this->ana->id, 'type' => MessageType::Audio]);
        $path = 'attachments/chat/'.$message->id.'.webm';
        Storage::disk('local')->put($path, 'audio');
        $attachment = $message->attachments()->create([
            'user_id' => $this->ana->id, 'disk' => 'local', 'path' => $path,
            'original_name' => 'nota.webm', 'mime' => 'audio/webm', 'size' => 5,
        ]);

        return AudioTranscription::query()->create([
            'message_id' => $message->id,
            'attachment_id' => $attachment->id,
            'status' => $status,
            ...$attributes,
        ]);
    };
});

it('solo el admin entra', function (string $who, int|string $expected) {
    $people = [
        'empleado' => $this->ana,
        'responsable' => User::factory()->departmentManager()->create(),
        'cliente' => User::factory()->client()->create(),
    ];

    $response = $this->actingAs($people[$who])->get('/admin/transcripciones');

    is_int($expected) ? $response->assertStatus($expected) : $response->assertRedirect($expected);
})->with([
    ['empleado', 403],
    ['responsable', 403],
    ['cliente', '/portal'],
]);

it('un invitado va al login', function () {
    $this->get('/admin/transcripciones')->assertRedirect('/login');
});

it('lista las transcripciones con su estado, intentos, error, duración y tiempo de proceso', function () {
    ($this->transcription)($this->chat, TranscriptionStatus::Done, ['attempts' => 1, 'audio_duration_ms' => 61_000, 'processing_ms' => 212_000, 'text' => 'Nunca se enseña']);
    $failed = ($this->transcription)($this->chat, TranscriptionStatus::Failed, ['attempts' => 3, 'last_error' => 'El transcriptor no responde']);
    ($this->transcription)($this->chat, TranscriptionStatus::Pending);

    $this->actingAs($this->admin)
        ->get('/admin/transcripciones')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/transcriptions')
            ->where('filters.status', null)
            ->where('counts', ['pending' => 1, 'processing' => 0, 'failed' => 1, 'done' => 1])
            ->where('maxAudioSeconds', 300)
            ->has('transcriptions', 3)
            ->where('transcriptions.1.id', $failed->id)
            ->where('transcriptions.1.status', 'failed')
            ->where('transcriptions.1.attempts', 3)
            ->where('transcriptions.1.last_error', 'El transcriptor no responde')
            ->where('transcriptions.1.can_retry', true)
            ->where('transcriptions.1.conversation', ['type' => 'project', 'label' => 'ARR-WEB · Web corporativa'])
            ->where('transcriptions.1.author', 'Ana Audio')
            ->where('transcriptions.1.url', "/chat/{$this->chat->id}?mensaje={$failed->message_id}")
            ->where('transcriptions.2.status', 'done')
            ->where('transcriptions.2.audio_duration_ms', 61_000)
            ->where('transcriptions.2.processing_ms', 212_000)
            ->where('transcriptions.2.can_retry', false)
            ->missing('transcriptions.2.text'));
});

it('filtra por estado', function (string $filter, string $status) {
    foreach (TranscriptionStatus::cases() as $case) {
        ($this->transcription)($this->chat, $case);
    }

    $this->actingAs($this->admin)
        ->get("/admin/transcripciones?estado={$filter}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.status', $filter)
            ->has('transcriptions', 1)
            ->where('transcriptions.0.status', $status));
})->with([
    ['pendientes', 'pending'],
    ['en-curso', 'processing'],
    ['fallidas', 'failed'],
    ['hechas', 'done'],
]);

it('un filtro desconocido es un error de validación', function () {
    $this->actingAs($this->admin)->get('/admin/transcripciones?estado=todas')->assertSessionHasErrors('estado');
});

it('de las conversaciones directas no enseña quién ni dónde', function () {
    ($this->transcription)($this->direct, TranscriptionStatus::Failed);

    $this->actingAs($this->admin)
        ->get('/admin/transcripciones')
        ->assertInertia(fn (Assert $page) => $page
            ->where('transcriptions.0.conversation', ['type' => 'direct', 'label' => null])
            ->where('transcriptions.0.author', null)
            ->where('transcriptions.0.url', null)
            ->where('transcriptions.0.can_retry', true));
});

it('señala los audios que según el transcriptor pasan de la duración máxima', function () {
    Setting::set('max_audio_seconds', 60);
    ($this->transcription)($this->chat, TranscriptionStatus::Done, ['audio_duration_ms' => 90_000]);
    ($this->transcription)($this->chat, TranscriptionStatus::Done, ['audio_duration_ms' => 61_000]);

    $this->actingAs($this->admin)
        ->get('/admin/transcripciones')
        ->assertInertia(fn (Assert $page) => $page
            ->where('maxAudioSeconds', 60)
            ->where('transcriptions.0.over_limit', false)
            ->where('transcriptions.1.over_limit', true));
});

it('relanza una transcripción fallida y acaba con su texto', function () {
    $failed = ($this->transcription)($this->chat, TranscriptionStatus::Failed, ['attempts' => 3, 'last_error' => 'Caído']);

    $this->actingAs($this->admin)
        ->from('/admin/transcripciones')
        ->post("/admin/transcripciones/{$failed->id}/relanzar")
        ->assertRedirect('/admin/transcripciones')
        ->assertInertiaFlash('toast.type', 'success');

    expect($failed->fresh()->status)->toBe(TranscriptionStatus::Done)
        ->and($failed->fresh()->text)->toBe('Texto de prueba')
        ->and($failed->fresh()->attempts)->toBe(4);
});

it('relanzar una hecha no hace nada y una en curso reciente no se toca', function () {
    $done = ($this->transcription)($this->chat, TranscriptionStatus::Done, ['text' => 'Hecha']);
    $busy = ($this->transcription)($this->chat, TranscriptionStatus::Processing, ['started_at' => now()->subMinutes(5), 'attempts' => 1]);

    $this->actingAs($this->admin)->post("/admin/transcripciones/{$done->id}/relanzar")->assertInertiaFlash('toast.type', 'info');
    $this->actingAs($this->admin)->post("/admin/transcripciones/{$busy->id}/relanzar")->assertInertiaFlash('toast.type', 'error');

    expect($this->fake->calls)->toBe(0)
        ->and($busy->fresh()->status)->toBe(TranscriptionStatus::Processing);
});

it('una en curso desde hace mucho (worker caído) sí se puede relanzar', function () {
    $stale = ($this->transcription)($this->chat, TranscriptionStatus::Processing, ['started_at' => now()->subHours(2), 'attempts' => 1]);

    $this->actingAs($this->admin)
        ->get('/admin/transcripciones')
        ->assertInertia(fn (Assert $page) => $page->where('transcriptions.0.can_retry', true));

    $this->actingAs($this->admin)->post("/admin/transcripciones/{$stale->id}/relanzar")->assertInertiaFlash('toast.type', 'success');

    expect($stale->fresh()->status)->toBe(TranscriptionStatus::Done);
});

it('relanza todas las fallidas en bloque', function () {
    $failed = collect(range(1, 3))->map(fn () => ($this->transcription)($this->chat, TranscriptionStatus::Failed, ['attempts' => AudioTranscriptions::MAX_ATTEMPTS, 'admin_notified_at' => now()]));
    $pending = ($this->transcription)($this->direct, TranscriptionStatus::Pending);

    $this->actingAs($this->admin)
        ->post('/admin/transcripciones/relanzar-fallidas')
        ->assertInertiaFlash('toast.message', 'Se han relanzado 3 transcripciones fallidas.');

    expect($failed->every(fn (AudioTranscription $t) => $t->fresh()->status === TranscriptionStatus::Done))->toBeTrue()
        ->and($failed->every(fn (AudioTranscription $t) => $t->fresh()->admin_notified_at === null))->toBeTrue()
        ->and($pending->fresh()->status)->toBe(TranscriptionStatus::Pending)
        ->and($this->fake->calls)->toBe(3);

    $this->actingAs($this->admin)
        ->post('/admin/transcripciones/relanzar-fallidas')
        ->assertInertiaFlash('toast.message', 'No había transcripciones fallidas que relanzar.');
});

it('si el motor vuelve a fallar al relanzar (cola síncrona), la página no se rompe', function () {
    $this->fake->respondWith(new TranscriptionFailed('Sigue caído'));
    $failed = ($this->transcription)($this->chat, TranscriptionStatus::Failed, ['attempts' => 3]);

    $this->actingAs($this->admin)
        ->post("/admin/transcripciones/{$failed->id}/relanzar")
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'warning')
        ->assertInertiaFlash('toast.message', 'Se ha relanzado, pero ha vuelto a fallar: Sigue caído');

    expect($failed->fresh()->status)->toBe(TranscriptionStatus::Failed)
        ->and($failed->fresh()->last_error)->toBe('Sigue caído');
});

it('nadie más que el admin relanza', function () {
    $failed = ($this->transcription)($this->chat, TranscriptionStatus::Failed);

    foreach ([$this->ana, User::factory()->departmentManager()->create()] as $user) {
        $this->actingAs($user)->post("/admin/transcripciones/{$failed->id}/relanzar")->assertForbidden();
        $this->actingAs($user)->post('/admin/transcripciones/relanzar-fallidas')->assertForbidden();
    }

    expect($failed->fresh()->status)->toBe(TranscriptionStatus::Failed);
});

it('todo audio publicado aparece con su transcripción hecha', function () {
    $samples = str_repeat("\0\0", 16000);
    $path = (string) tempnam(sys_get_temp_dir(), 'c3wav');
    file_put_contents($path, 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16).'data'.pack('V', strlen($samples)).$samples);
    app(MessageWriter::class)->post($this->ana, $this->chat, null, audio: new Illuminate\Http\UploadedFile($path, 'nota.wav', null, null, true), audioDurationMs: 1000);

    $this->actingAs($this->admin)
        ->get('/admin/transcripciones?estado=hechas')
        ->assertInertia(fn (Assert $page) => $page
            ->has('transcriptions', 1)
            ->where('transcriptions.0.attempts', 1)
            ->where('transcriptions.0.engine', 'fake'));
});
