<?php

use App\Broadcasting\WebPushChannel;
use App\Broadcasting\WebPushMessage;
use App\Domain\Notifications\NotificationPreferences;
use App\Models\User;
use App\Notifications\Chat\ChatDirectMessageNotification;
use App\Notifications\Chat\ChatEveryoneNotification;
use App\Notifications\Chat\ChatMentionNotification;
use App\Notifications\Chat\TranscriptionsFailing;
use App\Notifications\Tasks\TaskAssignedNotification;
use Minishlink\WebPush\VAPID;

/*
| Integración de la Fase 7 con el chat (D-073 y D-072): los avisos del chat deciden sus canales
| con NotificationPreferences como cualquier AppNotification; ChatNotices solo puede quitar el
| aviso del navegador (conversación silenciada o sin navegadores). El canal de Web Push existe
| cuando las claves VAPID del .env son válidas.
*/

beforeEach(function () {
    $this->preferences = app(NotificationPreferences::class);
    $this->luis = User::factory()->employee()->create(['name' => 'Luis']);
    config(['notifications.channels.push' => WebPushChannel::class]);
    $this->direct = fn (bool $push = true): ChatDirectMessageNotification => new ChatDirectMessageNotification(3, 9, null, 'Ana', 'Hola', $push);
    $this->mention = fn (bool $push = true): ChatMentionNotification => new ChatMentionNotification(3, 9, 'Web', 'Ana', 'Hola', $push);
});

test('config/notifications.php fija el canal de Web Push solo con claves VAPID válidas', function () {
    $keys = VAPID::createVapidKeys();
    $saved = array_intersect_key($_SERVER, array_flip(['VAPID_PUBLIC_KEY', 'VAPID_PRIVATE_KEY', 'VAPID_SUBJECT']));
    $load = function (array $env): mixed {
        foreach ($env as $name => $value) {
            $_SERVER[$name] = $value;
        }

        return (require config_path('notifications.php'))['channels']['push'];
    };

    try {
        expect($load(['VAPID_PUBLIC_KEY' => $keys['publicKey'], 'VAPID_PRIVATE_KEY' => $keys['privateKey'], 'VAPID_SUBJECT' => 'mailto:avisos@audaxstudio.com']))->toBe(WebPushChannel::class)
            ->and($load(['VAPID_PUBLIC_KEY' => 'cambiar-esta-clave-publica-vapid']))->toBeNull()
            ->and($load(['VAPID_PUBLIC_KEY' => $keys['publicKey'], 'VAPID_SUBJECT' => 'avisos@audaxstudio.com']))->toBeNull();
    } finally {
        unset($_SERVER['VAPID_PUBLIC_KEY'], $_SERVER['VAPID_PRIVATE_KEY'], $_SERVER['VAPID_SUBJECT']);
        $_SERVER = [...$_SERVER, ...$saved];
    }
});

test('por defecto, los avisos del chat van a la campana y al navegador', function () {
    expect(($this->direct)()->via($this->luis))->toBe(['database', WebPushChannel::class])
        ->and(($this->mention)()->via($this->luis))->toBe(['database', WebPushChannel::class])
        ->and((new ChatEveryoneNotification(3, 9, 'Web', 'Ana', 'Hola', true))->via($this->luis))->toBe(['database', WebPushChannel::class]);
});

test('sin el canal de Web Push configurado, solo la campana', function () {
    config(['notifications.channels.push' => null]);

    expect(($this->direct)()->via($this->luis))->toBe(['database']);
});

test('una conversación silenciada o sin navegadores (push = false) nunca llega al navegador, aunque la persona lo quiera', function () {
    expect(($this->direct)(false)->via($this->luis))->toBe(['database'])
        ->and(($this->mention)(false)->via($this->luis))->toBe(['database']);
});

test('las preferencias de la persona mandan: quitar el navegador o añadir el email', function () {
    $this->preferences->update($this->luis, [
        'chat.direct' => ['push' => false],
        'chat.mention' => ['email' => true],
    ], false);
    $this->luis->refresh();

    expect(($this->direct)()->via($this->luis))->toBe(['database'])
        ->and(($this->mention)()->via($this->luis))->toBe(['database', 'mail', WebPushChannel::class])
        ->and(($this->mention)()->toMail($this->luis)->subject)->toBe('Ana te ha mencionado en «Web»');
});

test('con el resumen diario, el email del chat se queda en la campana y entra en el resumen', function () {
    $this->preferences->update($this->luis, ['chat.mention' => ['email' => true]], true);
    $this->luis->refresh();

    expect(($this->mention)()->via($this->luis))->toBe(['database', WebPushChannel::class])
        ->and($this->preferences->digestKinds($this->luis))->toContain('chat.mention')->not->toContain('chat.direct');
});

test('el aviso de transcripciones que fallan es obligatorio para el admin: campana y email', function () {
    $admin = User::factory()->admin()->create();
    $this->preferences->update($admin, ['system.transcriptions_failing' => ['app' => false, 'email' => false]], false);
    $admin->refresh();

    expect((new TranscriptionsFailing(2))->via($admin))->toBe(['database', 'mail'])
        ->and($admin->notification_preferences['events'])->toBe([]);
});

test('el aviso del navegador del chat agrupa por conversación; el genérico, por tipo de aviso', function () {
    $chat = ($this->direct)()->toWebPush($this->luis);
    $task = (new TaskAssignedNotification(7, 2, 'Portada', 'Ana', 'Web'))->toWebPush($this->luis);

    expect($chat)->toBeInstanceOf(WebPushMessage::class)
        ->and($chat?->tag)->toBe('conversation-3')
        ->and($chat?->url)->toBe('/tiempo-real/conversaciones/3/abrir?mensaje=9')
        ->and($task?->tag)->toBe('task.assigned')
        ->and(($this->direct)()->viaQueues())->toMatchArray(['database' => 'default', 'mail' => 'mail', WebPushChannel::class => 'default']);
});
