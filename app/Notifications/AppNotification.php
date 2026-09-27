<?php

namespace App\Notifications;

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
 * Los canales los decide SOLO NotificationPreferences (D-073): las subclases no sobrescriben via().
 * Van por cola: el email por la cola `mail` y el resto por `default`. Toda notificación se puede
 * enviar por email (toMail genérico) y por Web Push (toPushPayload), salvo que su evento no lo ofrezca.
 */
abstract class AppNotification extends Notification implements ShouldQueue
{
    use Queueable;

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
        return app(NotificationPreferences::class)->channelsFor($notifiable, $this->kind())
            ?? $this->fallbackChannels();
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
        return [
            'database' => 'default',
            'mail' => 'mail',
        ];
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
}
