<?php

use App\Domain\Chat\ConversationDirectory;
use App\Domain\Chat\MessageWriter;
use App\Domain\Chat\Transcription\FakeTranscriber;
use App\Domain\Chat\Transcription\TranscriptionService;
use App\Events\Chat\BroadcastAudioTranscribed;
use App\Events\Chat\BroadcastConversationActivity;
use App\Events\Chat\BroadcastConversationRead;
use App\Events\Chat\BroadcastMessagePosted;
use App\Events\Chat\BroadcastMessageUpdated;
use App\Models\Project;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
| Tiempo real (Fase 6, D-068): lo que escribe MessageWriter sale por la COLA a los canales
| privados, con un payload mínimo (ids, sin el texto) y solo a quien puede verlo.
*/

beforeEach(function () {
    Storage::fake('local');
    $this->directory = app(ConversationDirectory::class);
    $this->writer = app(MessageWriter::class);
    $this->ana = User::factory()->employee()->create(['name' => 'Ana']);
    $this->luis = User::factory()->employee()->create(['name' => 'Luis']);
    $this->eva = User::factory()->employee()->create(['name' => 'Eva']);
    $this->project = Project::factory()->create();
    foreach ([$this->ana, $this->luis, $this->eva] as $member) {
        $this->project->addMember($member);
    }
    $this->chat = $this->directory->forProject($this->project);

    // Broadcaster que guarda lo que se enviaría a Reverb (canales, nombre y datos).
    $this->sent = new ArrayObject;
    $sent = $this->sent;
    app(BroadcastManager::class)->extend('capture', fn () => new class($sent) extends Broadcaster
    {
        public function __construct(private readonly ArrayObject $sent) {}

        public function auth($request)
        {
            return null;
        }

        public function validAuthenticationResponse($request, $result)
        {
            return $result;
        }

        public function broadcast(array $channels, $event, array $payload = []): void
        {
            $this->sent->append(['channels' => $this->formatChannels($channels), 'event' => $event, 'payload' => $payload]);
        }
    });
    config(['broadcasting.default' => 'capture', 'broadcasting.connections.capture' => ['driver' => 'capture']]);

    $this->broadcasts = fn (string $event): array => array_values(array_filter(
        $this->sent->getArrayCopy(),
        fn (array $item): bool => $item['event'] === $event,
    ));
});

it('los eventos de tiempo real van por la cola, nunca al instante, y un fallo al encolar no rompe nada', function () {
    foreach ([BroadcastMessagePosted::class, BroadcastMessageUpdated::class, BroadcastConversationRead::class, BroadcastAudioTranscribed::class, BroadcastConversationActivity::class] as $class) {
        $interfaces = class_implements($class);
        expect($interfaces)->toHaveKey(ShouldBroadcast::class)
            ->and($interfaces)->toHaveKey(ShouldRescue::class)
            ->and($interfaces)->not->toHaveKey(ShouldBroadcastNow::class);
    }

    Queue::fake();
    $this->writer->post($this->ana, $this->chat, 'Hola');

    Queue::assertPushed(BroadcastEvent::class, fn (BroadcastEvent $job): bool => $job->event instanceof BroadcastMessagePosted && $job->afterCommit === true);
    Queue::assertPushed(BroadcastEvent::class, fn (BroadcastEvent $job): bool => $job->event instanceof BroadcastConversationActivity);
});

it('un mensaje nuevo sale a su conversación con ids y tipo, sin el texto', function () {
    $message = $this->writer->post($this->ana, $this->chat, 'Texto que no debe viajar por Reverb <@'.$this->luis->id.'>');

    $posted = ($this->broadcasts)('message.posted');
    expect($posted)->toHaveCount(1)
        ->and($posted[0]['channels'])->toBe(["private-conversation.{$this->chat->id}"])
        ->and($posted[0]['payload'])->toMatchArray([
            'conversation_id' => $this->chat->id,
            'message_id' => $message->id,
            'type' => 'text',
            'user_id' => $this->ana->id,
            'parent_id' => null,
        ])
        ->and(array_keys($posted[0]['payload']))->toEqualCanonicalizing(['conversation_id', 'message_id', 'type', 'user_id', 'parent_id', 'created_at', 'socket'])
        ->and(json_encode($posted[0]['payload']))->not->toContain('Texto que no debe viajar');
});

it('la actividad para los contadores va al canal personal de cada participante, salvo el autor y quien ya no está', function () {
    $this->project->members()->detach($this->eva->id);
    $outsider = User::factory()->employee()->create();

    $message = $this->writer->post($this->ana, $this->chat, 'Hola');

    $activity = ($this->broadcasts)('chat.activity');
    $expected = collect([$this->luis->id, $this->project->owner_user_id])->reject(fn (int $id) => $id === $this->ana->id)->unique()->sort()->values()
        ->map(fn (int $id): string => "private-App.Models.User.{$id}")->all();

    expect($activity)->toHaveCount(1)
        ->and($activity[0]['channels'])->toEqualCanonicalizing($expected)
        ->and($activity[0]['channels'])->not->toContain("private-App.Models.User.{$this->ana->id}")
        ->and($activity[0]['channels'])->not->toContain("private-App.Models.User.{$this->eva->id}")
        ->and($activity[0]['channels'])->not->toContain("private-App.Models.User.{$outsider->id}")
        ->and($activity[0]['payload'])->toMatchArray(['conversation_id' => $this->chat->id, 'message_id' => $message->id, 'user_id' => $this->ana->id]);
});

it('los mensajes de sistema también se emiten, sin autor', function () {
    $message = $this->writer->system($this->chat, 'hour_bank.threshold', ['threshold' => 90]);

    expect(($this->broadcasts)('message.posted')[0]['payload'])->toMatchArray(['message_id' => $message->id, 'type' => 'system', 'user_id' => null])
        ->and(($this->broadcasts)('chat.activity')[0]['channels'])->toContain("private-App.Models.User.{$this->ana->id}");
});

it('un cambio en un mensaje sale con la pista de qué ha cambiado', function () {
    $admin = User::factory()->admin()->create();
    $message = $this->writer->post($this->ana, $this->chat, 'Primera versión');
    $changes = function () use ($message): array {
        $items = array_filter(($this->broadcasts)('message.updated'), fn (array $item): bool => $item['payload']['message_id'] === $message->id);

        return array_values(array_map(fn (array $item): string => $item['payload']['change'], $items));
    };

    $this->writer->edit($this->ana, $message->fresh(), 'Segunda versión');
    $this->writer->setPinned($this->luis, $message->fresh(), true);
    $this->writer->setPinned($this->luis, $message->fresh(), false);
    $this->writer->toggleReaction($this->luis, $message->fresh(), '👍');
    $this->writer->setHidden($admin, $message->fresh(), true);
    $this->writer->setHidden($admin, $message->fresh(), false);
    $this->writer->delete($this->ana, $message->fresh());

    expect($changes())->toBe(['edited', 'pinned', 'unpinned', 'updated', 'hidden', 'unhidden', 'deleted']);

    $updated = ($this->broadcasts)('message.updated')[0];
    expect($updated['channels'])->toBe(["private-conversation.{$this->chat->id}"])
        ->and(json_encode($updated['payload']))->not->toContain('versión');
});

it('leer sale a la conversación («leído por») y al canal personal de quien lee', function () {
    $message = $this->writer->post($this->ana, $this->chat, 'Hola');

    $this->writer->markRead($this->luis, $this->chat, $message->id);

    $read = ($this->broadcasts)('conversation.read');
    expect($read)->toHaveCount(1)
        ->and($read[0]['channels'])->toEqualCanonicalizing(["private-conversation.{$this->chat->id}", "private-App.Models.User.{$this->luis->id}"])
        ->and($read[0]['payload'])->toMatchArray(['conversation_id' => $this->chat->id, 'user_id' => $this->luis->id, 'last_read_message_id' => $message->id]);

    // Leer hacia atrás no emite nada.
    $this->writer->markRead($this->luis, $this->chat, $message->id);
    expect(($this->broadcasts)('conversation.read'))->toHaveCount(1);
});

it('cuando un audio tiene su texto, la conversación se entera', function () {
    $this->app->instance(TranscriptionService::class, new FakeTranscriber('Hola equipo'));
    $samples = str_repeat("\0\0", 1600);
    $wav = 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16).'data'.pack('V', strlen($samples)).$samples;
    $path = tempnam(sys_get_temp_dir(), 'rt');
    file_put_contents($path, $wav);

    $message = $this->writer->post($this->ana, $this->chat, null, audio: new UploadedFile($path, 'nota.wav', null, null, true));

    $transcribed = ($this->broadcasts)('audio.transcribed');
    expect($transcribed)->toHaveCount(1)
        ->and($transcribed[0]['channels'])->toBe(["private-conversation.{$this->chat->id}"])
        ->and($transcribed[0]['payload'])->toMatchArray(['conversation_id' => $this->chat->id, 'message_id' => $message->id, 'status' => 'done'])
        ->and(json_encode($transcribed[0]['payload']))->not->toContain('Hola equipo');
});

it('cada evento del dominio produce su evento de broadcast (Event::fake)', function () {
    Event::fake([BroadcastMessagePosted::class, BroadcastConversationActivity::class, BroadcastConversationRead::class]);
    $dm = $this->directory->direct($this->ana, $this->luis);

    $message = $this->writer->post($this->ana, $dm, 'Hola');
    $this->writer->markRead($this->luis, $dm, $message->id);

    Event::assertDispatched(BroadcastMessagePosted::class, fn (BroadcastMessagePosted $event): bool => $event->conversationId === $dm->id
        && $event->broadcastOn()[0]->name === "private-conversation.{$dm->id}" && $event->broadcastAs() === 'message.posted');
    Event::assertDispatched(BroadcastConversationActivity::class, fn (BroadcastConversationActivity $event): bool => $event->recipientIds === [$this->luis->id]);
    Event::assertDispatched(BroadcastConversationRead::class, fn (BroadcastConversationRead $event): bool => $event->userId === $this->luis->id && $event->lastReadMessageId === $message->id);
});
