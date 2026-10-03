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
use App\Search\Sources\MessageSource;
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

describe('menciones <@ID> escritas a mano', function () {
    beforeEach(function () {
        // Óscar es de la plantilla, pero no participa en el chat del proyecto de Sara.
        $this->outsider = User::factory()->employee()->create(['name' => 'Óscar Fuera']);
        $this->message = $this->writer->post($this->ana, $this->ownChat, "<@{$this->sara->id}> <@{$this->outsider->id}> <@{$this->luis->id}> propuesta");
        // Luis estaba cuando se le mencionó; después sale del proyecto: su mención sigue siendo suya.
        $this->directory->leave($this->ownChat, $this->luis->id);
        $this->expected = '@Sara Colaboradora @Persona desconocida @Luis Plantilla propuesta';
    });

    it('solo resuelven a participantes o a quien tiene su mención guardada, para cualquiera que mire', function () {
        foreach ([$this->sara, $this->ana] as $viewer) {
            $users = collect($this->actingAs($viewer)->getJson("/chat/{$this->ownChat->id}/mensajes")->assertOk()->json('users'))->pluck('id')->all();

            expect($users)->toContain($this->luis->id)
                ->and($users)->toContain($this->sara->id)
                ->and($users)->not->toContain($this->outsider->id);
        }
    });

    it('la lista de conversaciones, los fijados, Inicio, la búsqueda y la tarea sugerida dicen «Persona desconocida»', function () {
        $listed = collect($this->actingAs($this->sara)->getJson('/chat/conversaciones')->assertOk()->json('conversations'))->firstWhere('id', $this->ownChat->id);
        expect($listed['last_message']['preview'])->toBe($this->expected);

        $this->writer->setPinned($this->ana, $this->message, true);
        expect($this->actingAs($this->sara)->getJson("/chat/{$this->ownChat->id}/fijados")->assertOk()->json('pinned.0.excerpt'))->toBe($this->expected);

        expect(app(HomeChatSummary::class)->for($this->sara)['mentions'][0]['excerpt'])->toBe($this->expected);

        expect(app(MessageSource::class)->find($this->sara, 'propuesta', 10)[0]['excerpt'])->toContain('@Persona desconocida')
            ->not->toContain('Óscar');

        expect($this->actingAs($this->sara)->getJson("/chat/mensajes/{$this->message->id}/tarea")->assertOk()->json('title'))->toBe($this->expected);
    });
});
