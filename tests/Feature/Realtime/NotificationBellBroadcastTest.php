<?php

use App\Events\Chat\BroadcastAppNotification;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Notifications\Chat\ChatDirectMessageNotification;
use App\Notifications\Chat\TranscriptionsFailing;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/*
| Campana en tiempo real (D-037 → Fase 6): toda notificación que se guarda en la base de datos,
| sea del área que sea, sale también por el canal personal con el formato de la campana.
*/

beforeEach(function () {
    config(['realtime.enabled' => true]);
    Event::fake([BroadcastAppNotification::class]);
    $this->luis = User::factory()->employee()->create();
});

it('cada notificación de la campana sale por el canal personal con el formato de /notificaciones/recientes', function () {
    $this->luis->notify(new ChatDirectMessageNotification(7, 42, null, 'Ana', 'Hola'));

    $stored = $this->luis->notifications()->sole();
    Event::assertDispatched(BroadcastAppNotification::class, function (BroadcastAppNotification $event) use ($stored): bool {
        return $event->broadcastOn()[0]->name === "private-App.Models.User.{$this->luis->id}"
            && $event->broadcastAs() === 'notification.created'
            && $event->broadcastWith() === ['notification' => [
                'id' => $stored->id,
                'data' => [
                    'kind' => 'chat.direct',
                    'title' => 'Ana te ha escrito',
                    'body' => 'Hola',
                    'url' => '/tiempo-real/conversaciones/7/abrir?mensaje=42',
                    'icon' => 'message-square',
                ],
                'read_at' => null,
                'created_at' => $stored->created_at?->toIso8601ZuluString(),
            ]];
    });
});

it('vale para las notificaciones de cualquier área, no solo las del chat', function () {
    $admin = User::factory()->admin()->create();

    $admin->notify(new TranscriptionsFailing(2));

    Event::assertDispatched(BroadcastAppNotification::class, fn (BroadcastAppNotification $event): bool => $event->userId === $admin->id
        && $event->notification['data']['kind'] === 'system.transcriptions_failing'
        && $event->notification['data']['url'] === '/admin/transcripciones');
});

it('solo el canal database genera el aviso en vivo', function () {
    $mailOnly = new class extends AppNotification
    {
        public function kind(): string
        {
            return 'test.mail';
        }

        public function title(object $notifiable): string
        {
            return 'Solo correo';
        }

        public function url(object $notifiable): ?string
        {
            return null;
        }

        public function via(object $notifiable): array
        {
            return ['mail'];
        }

        public function toMail(object $notifiable): MailMessage
        {
            return (new MailMessage)->line('Solo correo');
        }
    };

    $this->luis->notifyNow($mailOnly);

    Event::assertNotDispatched(BroadcastAppNotification::class);
});

it('sin tiempo real no emite nada: la campana consulta y los avisos «sin cola» siguen sin cola', function () {
    config(['realtime.enabled' => false]);
    Queue::fake();

    $this->luis->notifyNow(new ChatDirectMessageNotification(7, 42, null, 'Ana', 'Hola'));

    expect($this->luis->notifications()->count())->toBe(1);
    Event::assertNotDispatched(BroadcastAppNotification::class);
    Queue::assertNothingPushed();
});
