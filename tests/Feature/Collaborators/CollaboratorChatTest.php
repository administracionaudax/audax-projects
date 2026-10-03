<?php

use App\Domain\Chat\ConversationDirectory;
use App\Domain\Chat\MessageWriter;
use App\Events\Chat\BroadcastConversationActivity;
use App\Http\Resources\Chat\HomeChatSummary;
use App\Models\Project;
use App\Models\User;
use App\Notifications\Chat\ChatDirectMessageNotification;
use App\Notifications\Chat\ChatEveryoneNotification;
use App\Notifications\Chat\ChatMentionNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| Chat y colaborador externo (D-134), red de seguridad: aunque siga como participante de una
| directa, un grupo o el chat de un proyecto ajeno (de antes de ser colaborador), no recibe sus
| avisos, no le cuentan como mención en Inicio ni le llega su actividad en tiempo real.
*/

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    $this->directory = app(ConversationDirectory::class);
    $this->writer = app(MessageWriter::class);

    $this->sara = User::factory()->collaborator()->create(['name' => 'Sara Colaboradora']);
    $this->ana = User::factory()->employee()->create(['name' => 'Ana Plantilla']);
    $this->luis = User::factory()->employee()->create(['name' => 'Luis Plantilla']);

    $this->ownChat = $this->directory->forProject(Project::factory()->withMembers([$this->sara, $this->ana, $this->luis])->create());
    $this->foreignChat = $this->directory->forProject(Project::factory()->withMembers([$this->ana, $this->luis])->create());

    // Restos de cuando era de la plantilla: sigue como participante activa.
    $this->direct = $this->directory->direct($this->ana, $this->luis);
    $this->group = $this->directory->group($this->ana, 'Café', [$this->luis->id]);
    foreach ([$this->direct, $this->group, $this->foreignChat] as $conversation) {
        $this->directory->join($conversation, $this->sara->id);
    }
});

it('no recibe avisos de directas, grupos ni chats de proyectos ajenos; de los suyos, sí', function () {
    // Los avisos se deciden en la cola (síncrona en los tests) al publicar cada mensaje.
    $this->writer->post($this->ana, $this->direct, 'Hola');
    $this->writer->post($this->ana, $this->group, "@todos y <@{$this->sara->id}>");
    $this->writer->post($this->ana, $this->foreignChat, "<@{$this->sara->id}> mira esto");

    Notification::assertNotSentTo($this->sara, ChatDirectMessageNotification::class);
    Notification::assertNotSentTo($this->sara, ChatEveryoneNotification::class);
    Notification::assertNotSentTo($this->sara, ChatMentionNotification::class);
    Notification::assertSentTo($this->luis, ChatDirectMessageNotification::class);
    Notification::assertSentTo($this->luis, ChatEveryoneNotification::class);

    $this->writer->post($this->ana, $this->ownChat, "<@{$this->sara->id}> mira esto");
    Notification::assertSentTo($this->sara, ChatMentionNotification::class);
});

it('en Inicio solo le cuentan las menciones de las conversaciones de sus proyectos', function () {
    $this->writer->post($this->ana, $this->foreignChat, "<@{$this->sara->id}> ajeno");
    $this->writer->post($this->ana, $this->group, "<@{$this->sara->id}> grupo");
    $own = $this->writer->post($this->ana, $this->ownChat, "<@{$this->sara->id}> suyo");

    $mentions = app(HomeChatSummary::class)->for($this->sara)['mentions'];

    expect(array_column($mentions, 'id'))->toBe([$own->id]);
});

it('la actividad en tiempo real no le llega de directas, grupos ni proyectos ajenos', function () {
    config(['realtime.enabled' => true]);
    Event::fake([BroadcastConversationActivity::class]);

    foreach ([$this->direct, $this->group, $this->foreignChat] as $conversation) {
        $this->writer->post($this->ana, $conversation, 'Hola');
    }
    $this->writer->post($this->ana, $this->ownChat, 'Hola');

    $recipients = Event::dispatched(BroadcastConversationActivity::class)
        ->mapWithKeys(fn (array $event): array => [$event[0]->conversationId => $event[0]->recipientIds])
        ->all();

    expect($recipients[$this->direct->id])->toBe([$this->luis->id])
        ->and($recipients[$this->group->id])->toBe([$this->luis->id])
        ->and($recipients[$this->foreignChat->id])->toContain($this->luis->id)->not->toContain($this->sara->id)
        ->and($recipients[$this->ownChat->id])->toContain($this->sara->id);
});
