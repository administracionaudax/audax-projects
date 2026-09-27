<?php

use App\Broadcasting\ConversationViewers;
use App\Broadcasting\WebPushChannel;
use App\Domain\Chat\ConversationDirectory;
use App\Domain\Chat\MessageWriter;
use App\Listeners\Chat\SendChatNotices;
use App\Models\ConversationParticipant;
use App\Models\Project;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\Chat\ChatDirectMessageNotification;
use App\Notifications\Chat\ChatEveryoneNotification;
use App\Notifications\Chat\ChatExcerpt;
use App\Notifications\Chat\ChatMentionNotification;
use App\Notifications\Chat\ChatMessageNotification;
use App\Notifications\Chat\ChatNotices;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Minishlink\WebPush\VAPID;

/*
| Avisos del chat (SPEC §12 y §13, D-072): menciones, @todos y mensajes directos, en la campana y
| (si está activado) en el navegador. Nunca al autor, a quien tiene la conversación abierta ni
| más de uno por conversación cada 5 minutos por persona.
*/

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    $this->directory = app(ConversationDirectory::class);
    $this->writer = app(MessageWriter::class);
    $this->ana = User::factory()->employee()->create(['name' => 'Ana']);
    $this->luis = User::factory()->employee()->create(['name' => 'Luis']);
    $this->eva = User::factory()->employee()->create(['name' => 'Eva']);
    $this->project = Project::factory()->create(['name' => 'Web de Arrieta']);
    foreach ([$this->ana, $this->luis, $this->eva] as $member) {
        $this->project->addMember($member);
    }
    $this->chat = $this->directory->forProject($this->project);
    $this->owner = User::query()->findOrFail($this->project->owner_user_id);
    $this->mute = fn (User $user, $conversation) => ConversationParticipant::query()
        ->where(['conversation_id' => $conversation->id, 'user_id' => $user->id])->update(['muted' => true]);
    $this->enablePush = function (): void {
        $keys = VAPID::createVapidKeys();
        config(['services.webpush.public_key' => $keys['publicKey'], 'services.webpush.private_key' => $keys['privateKey']]);
    };
    $this->subscribe = fn (User $user) => PushSubscription::query()->create([
        'user_id' => $user->id,
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/'.$user->id,
        'endpoint_hash' => PushSubscription::hashEndpoint('https://fcm.googleapis.com/fcm/send/'.$user->id),
        'public_key' => 'BIPUL12DLfytvTajnryr2PRdAgXS3HGKiLqndGcJGabyhHheJYlNGCeXl1dn18gSJ1WAkAPIxr4gK0_dQds4yiI',
        'auth_token' => 'ItX0h4SNvlrBpXCnvobGkw',
    ]);
});

it('una mención avisa solo a la persona mencionada, con el texto legible y el enlace a la conversación', function () {
    $message = $this->writer->post($this->ana, $this->chat, "**Hola** <@{$this->luis->id}>, mira el [enlace](https://example.com) y el `código`");

    Notification::assertSentTo($this->luis, ChatMentionNotification::class, function (ChatMentionNotification $notification) use ($message): bool {
        $data = $notification->toArray($this->luis);

        return $notification->messageId === $message->id
            && $data['kind'] === 'chat.mention'
            && $data['title'] === 'Ana te ha mencionado en «Web de Arrieta»'
            && $data['body'] === 'Hola @Luis, mira el enlace y el código'
            && $data['url'] === "/tiempo-real/conversaciones/{$this->chat->id}/abrir?mensaje={$message->id}"
            && $data['icon'] === 'at-sign'
            && $notification->via($this->luis) === ['database'];
    });
    Notification::assertNotSentTo([$this->ana, $this->eva, $this->owner], ChatMessageNotification::class);
});

it('@todos avisa a todos los participantes salvo al autor y a quien la tiene silenciada', function () {
    ($this->mute)($this->eva, $this->chat);

    $this->writer->post($this->ana, $this->chat, '@todos reunión a las 12');

    Notification::assertSentTo($this->luis, ChatEveryoneNotification::class, fn (ChatEveryoneNotification $n): bool => $n->toArray($this->luis)['title'] === 'Ana ha avisado a todos en «Web de Arrieta»');
    Notification::assertSentTo($this->owner, ChatEveryoneNotification::class);
    Notification::assertNotSentTo([$this->ana, $this->eva], ChatMessageNotification::class);
});

it('en una conversación silenciada la mención personal sigue llegando a la campana, pero no al navegador', function () {
    ($this->enablePush)();
    ($this->subscribe)($this->luis);
    ($this->subscribe)($this->eva);
    ($this->mute)($this->luis, $this->chat);

    $this->writer->post($this->ana, $this->chat, "@todos y en especial <@{$this->luis->id}>");

    Notification::assertSentTo($this->luis, ChatMentionNotification::class, fn (ChatMentionNotification $n): bool => $n->push === false && $n->via($this->luis) === ['database']);
    Notification::assertSentTo($this->eva, ChatEveryoneNotification::class, fn (ChatEveryoneNotification $n): bool => $n->push === true && $n->via($this->eva) === ['database', WebPushChannel::class]);
});

it('un mensaje directo avisa a la otra persona; si la ha silenciado, no', function () {
    $dm = $this->directory->direct($this->ana, $this->luis);
    $message = $this->writer->post($this->ana, $dm, 'Oye, ¿tienes un minuto?');

    Notification::assertSentTo($this->luis, ChatDirectMessageNotification::class, function (ChatDirectMessageNotification $n) use ($message): bool {
        $data = $n->toArray($this->luis);

        return $data['kind'] === 'chat.direct' && $data['title'] === 'Ana te ha escrito'
            && $data['body'] === 'Oye, ¿tienes un minuto?' && $n->messageId === $message->id;
    });
    Notification::assertNotSentTo($this->ana, ChatMessageNotification::class);

    $muted = $this->directory->direct($this->eva, $this->luis);
    ($this->mute)($this->luis, $muted);
    $this->writer->post($this->eva, $muted, 'Hola');
    Notification::assertNotSentTo($this->luis, ChatDirectMessageNotification::class, fn (ChatDirectMessageNotification $n): bool => $n->conversationId === $muted->id);
});

it('un mensaje sin mención en un chat de proyecto no avisa a nadie', function () {
    $this->writer->post($this->ana, $this->chat, 'Subo los cambios');

    Notification::assertNothingSent();
});

it('no avisa a quien tiene la conversación abierta', function () {
    $dm = $this->directory->direct($this->ana, $this->luis);
    app(ConversationViewers::class)->touch($dm->id, $this->luis->id);

    $this->writer->post($this->ana, $dm, 'Lo estás viendo');
    Notification::assertNothingSent();

    // Al cerrar la conversación (o pasado un minuto sin renovarla), vuelve a avisar.
    $this->travel(61)->seconds();
    $this->writer->post($this->ana, $dm, 'Ya no lo ves');
    Notification::assertSentToTimes($this->luis, ChatDirectMessageNotification::class, 1);
});

it('agrupa: como mucho un aviso por conversación cada 5 minutos por persona', function () {
    $dm = $this->directory->direct($this->ana, $this->luis);

    $this->writer->post($this->ana, $dm, 'Uno');
    $this->writer->post($this->ana, $dm, 'Dos');
    $this->writer->post($this->ana, $dm, 'Tres');
    Notification::assertSentToTimes($this->luis, ChatDirectMessageNotification::class, 1);

    $this->travel(4)->minutes();
    $this->writer->post($this->ana, $dm, 'Cuatro');
    Notification::assertSentToTimes($this->luis, ChatDirectMessageNotification::class, 1);

    $this->travel(2)->minutes();
    $this->writer->post($this->ana, $dm, 'Cinco');
    Notification::assertSentToTimes($this->luis, ChatDirectMessageNotification::class, 2);

    // Otra conversación tiene su propio turno.
    $this->writer->post($this->ana, $this->chat, "<@{$this->luis->id}> mira esto");
    Notification::assertSentToTimes($this->luis, ChatMentionNotification::class, 1);
});

it('leer la conversación (o abrirla) reinicia la agrupación', function () {
    $dm = $this->directory->direct($this->ana, $this->luis);
    $first = $this->writer->post($this->ana, $dm, 'Uno');
    $this->writer->markRead($this->luis, $dm, $first->id);

    $this->writer->post($this->ana, $dm, 'Dos');
    Notification::assertSentToTimes($this->luis, ChatDirectMessageNotification::class, 2);

    $this->actingAs($this->luis)->post("/tiempo-real/conversaciones/{$dm->id}/viendo")->assertNoContent();
    $this->actingAs($this->luis)->delete("/tiempo-real/conversaciones/{$dm->id}/viendo")->assertNoContent();
    $this->writer->post($this->ana, $dm, 'Tres');
    Notification::assertSentToTimes($this->luis, ChatDirectMessageNotification::class, 3);
});

it('nunca avisa a quien ya no participa, está desactivado o no es interno', function () {
    $this->project->members()->detach($this->eva->id);
    $this->luis->update(['is_active' => false]);

    $this->writer->post($this->ana, $this->chat, "@todos <@{$this->eva->id}> <@{$this->luis->id}>");

    Notification::assertNotSentTo([$this->eva, $this->luis->fresh(), $this->ana], ChatMessageNotification::class);
    Notification::assertSentTo($this->owner, ChatEveryoneNotification::class);
});

it('los mensajes de sistema, borrados u ocultados no avisan', function () {
    $this->writer->system($this->chat, 'hour_bank.threshold', ['threshold' => 90]);
    Notification::assertNothingSent();

    $message = $this->writer->post($this->ana, $this->chat, '@todos borrado');
    Notification::fake();
    $message->delete();
    expect(app(ChatNotices::class)->forMessage($message->id))->toBe([]);

    $hidden = $this->writer->post($this->ana, $this->chat, 'Oculto');
    $hidden->forceFill(['hidden_at' => now()])->save();
    expect(app(ChatNotices::class)->forMessage($hidden->id))->toBe([]);
    Notification::assertNothingSent();
});

it('el aviso del navegador sale solo si el servidor tiene VAPID y la persona un navegador suscrito', function () {
    $dm = $this->directory->direct($this->ana, $this->luis);
    ($this->subscribe)($this->luis);

    $this->writer->post($this->ana, $dm, 'Sin VAPID');
    Notification::assertSentTo($this->luis, ChatDirectMessageNotification::class, fn (ChatDirectMessageNotification $n): bool => $n->push === false);

    ($this->enablePush)();
    $this->travel(6)->minutes();
    $this->writer->post($this->ana, $dm, 'Con VAPID');
    Notification::assertSentTo($this->luis, ChatDirectMessageNotification::class, function (ChatDirectMessageNotification $n): bool {
        $push = $n->toWebPush($this->luis);

        return $n->push === true && $push !== null && $push->title === 'Ana te ha escrito' && $push->body === 'Con VAPID'
            && $push->tag === "conversation-{$n->conversationId}" && str_starts_with($push->url, '/tiempo-real/conversaciones/');
    });
});

it('el resumen del aviso quita el markdown, cambia las menciones por nombres y recorta', function () {
    expect(ChatExcerpt::plain('*Hola* _equipo_, ~~no~~ `sí` <@7> y <@8>', [7 => 'Ana']))->toBe('Hola equipo, no sí @Ana y @alguien')
        ->and(ChatExcerpt::plain('nombre_de_archivo y 2 * 3'))->toBe('nombre_de_archivo y 2 * 3')
        ->and(ChatExcerpt::plain("```php\necho 1;\n```"))->toBe('echo 1;')
        ->and(mb_strlen(ChatExcerpt::plain(str_repeat('palabra ', 60))))->toBeLessThanOrEqual(ChatExcerpt::LENGTH + 1);
});

it('decidir quién recibe aviso hace las mismas consultas con 4 que con 30 participantes', function () {
    $selects = function (): int {
        $message = $this->writer->post($this->ana, $this->chat, "@todos y <@{$this->luis->id}>, hola");
        $count = 0;
        DB::listen(function (QueryExecuted $query) use (&$count): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                $count++;
            }
        });

        app(ChatNotices::class)->forMessage($message->id);

        return $count;
    };
    $before = $selects();

    foreach (User::factory()->employee()->count(26)->create() as $member) {
        $this->project->addMember($member);
    }

    expect($selects())->toBe($before)->and($before)->toBeLessThanOrEqual(10);
});

it('solo encola el trabajo de avisos si el mensaje puede avisar a alguien', function () {
    Queue::fake();
    $queued = fn (): int => Queue::pushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === SendChatNotices::class)->count();
    $dm = $this->directory->direct($this->ana, $this->luis);

    $this->writer->post($this->ana, $this->chat, 'Subo los cambios');
    $this->writer->system($this->chat, 'hour_bank.threshold', ['threshold' => 90]);
    expect($queued())->toBe(0);

    $this->writer->post($this->ana, $this->chat, "<@{$this->luis->id}> mira");
    $this->writer->post($this->ana, $this->chat, '@todos a las 12');
    $this->writer->post($this->ana, $dm, 'Hola');
    expect($queued())->toBe(3);
});
