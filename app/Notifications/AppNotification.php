<?php

namespace App\Notifications;

use App\Broadcasting\SendsWebPush;
use App\Broadcasting\WebPushMessage;
use App\Domain\Notifications\NotificationPreferences;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Base de las notificaciones de la app (SPEC §13). Contrato del canal `database`, que pinta la
 * campana y /notificaciones (resources/js/types/domain.ts, AppNotificationData):
 *   kind   → identificador estable ("task.assigned", "hour_bank.threshold"…), con su evento en
 *            App\Domain\Notifications\NotificationCatalog
 *   title  → texto corto en español
 *   body   → detalle opcional (texto plano)
 *   url    → a dónde lleva al pulsar (relativa y estable, p. ej. /tareas/12)
 *   icon   → nombre de icono de lucide (opcional)
 * Los canales los decide SOLO NotificationPreferences (D-073): las subclases no sobrescriben via()
 * (los avisos del chat solo pueden QUITAR el del navegador, por las reglas de D-072).
 * Van por cola: el email por la cola `mail` y el resto por `default`. Toda notificación se puede
 * enviar por email (toMail genérico) y por Web Push (toWebPush, con el canal de la Fase 6 que fija
 * config('notifications.channels.push')), salvo que su evento no lo ofrezca.
 */
abstract class AppNotification extends Notification implements SendsWebPush, ShouldQueue
{
    use Queueable;

    /**
     * Canales de Laravel a los que se limita este envío, o null para todos los que decide
     * NotificationPreferences. Solo QUITA canales, nunca añade: lo usan los avisos de la Weekly
     * (10.5, D-201), cuyo recordatorio sale por el canal de su regla (si la persona lo quiere).
     *
     * @var list<string>|null
     */
    public ?array $onlyChannels = null;

    abstract public function kind(): string;

    abstract public function title(object $notifiable): string;

    public function body(object $notifiable): ?string
    {
        return null;
    }

    abstract public function url(object $notifiable): ?string;

    public function icon(): ?string
    {
        return null;
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = app(NotificationPreferences::class)->channelsFor($notifiable, $this->kind())
            ?? $this->fallbackChannels();

        if ($this->onlyChannels === null) {
            return $channels;
        }

        return array_values(array_intersect($channels, $this->onlyChannels));
    }

    /**
     * Canales si NotificationPreferences no decide (quien recibe no es un usuario).
     *
     * @return list<string>
     */
    protected function fallbackChannels(): array
    {
        return ['database'];
    }

    /**
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        $queues = [
            'database' => 'default',
            'mail' => 'mail',
        ];
        $push = config('notifications.channels.push');

        if (is_string($push) && $push !== '') {
            $queues[$push] = 'default';
        }

        return $queues;
    }

    /**
     * @return array{kind: string, title: string, body: string|null, url: string|null, icon: string|null}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind(),
            'title' => $this->title($notifiable),
            'body' => $this->body($notifiable),
            'url' => $this->url($notifiable),
            'icon' => $this->icon(),
        ];
    }

    /**
     * Email genérico con el título, el detalle y el enlace. Las notificaciones con un email propio
     * (bolsas, ausencias, resumen semanal) lo sobrescriben.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $name = $notifiable instanceof User ? $notifiable->name : null;
        $body = $this->body($notifiable);
        $url = $this->url($notifiable);

        $message = (new MailMessage)
            ->subject($this->title($notifiable))
            ->greeting($name !== null ? __('notifications.mail.greeting', ['name' => $name]) : __('notifications.mail.greeting_anonymous'))
            ->line($this->title($notifiable));

        if ($body !== null && $body !== '') {
            $message->line($body);
        }

        if ($url !== null) {
            $message->action(__('notifications.mail.action'), url($url));
        }

        return $message->salutation(__('notifications.mail.salutation', [
            'company' => (string) Setting::get('company_name', config('app.name')),
        ]));
    }

    /**
     * Contenido de un aviso Web Push (D-072): corto, sin datos que no deban salir del navegador.
     *
     * @return array{title: string, body: string, url: string, tag: string}
     */
    public function toPushPayload(object $notifiable): array
    {
        return [
            'title' => $this->title($notifiable),
            'body' => (string) $this->body($notifiable),
            'url' => $this->url($notifiable) ?? '/notificaciones',
            'tag' => $this->kind(),
        ];
    }

    /**
     * Aviso del navegador (WebPushChannel de la Fase 6) con el mismo contenido que toPushPayload.
     * La etiqueta agrupa los avisos del mismo tipo: uno nuevo sustituye al anterior.
     */
    public function toWebPush(object $notifiable): ?WebPushMessage
    {
        $payload = $this->toPushPayload($notifiable);

        return new WebPushMessage(
            $payload['title'],
            $payload['body'] === '' ? null : $payload['body'],
            $payload['url'],
            $payload['tag'],
        );
    }
}
